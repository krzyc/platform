<?php

namespace Oro\Bundle\ImportExportBundle\Strategy\Import;

use Doctrine\Common\Collections\Collection;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;

use Oro\Bundle\ImportExportBundle\Context\ContextInterface;
use Oro\Bundle\ImportExportBundle\Exception\LogicException;
use Oro\Bundle\ImportExportBundle\Exception\InvalidArgumentException;
use Oro\Bundle\ImportExportBundle\Field\FieldHelper;

class ImportStrategyHelper
{
    /**
     * @var ManagerRegistry
     */
    protected $managerRegistry;

    /**
     * @var ValidatorInterface
     */
    protected $validator;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var FieldHelper
     */
    protected $fieldHelper;

    /**
     * @param ManagerRegistry $managerRegistry
     * @param ValidatorInterface $validator
     * @param TranslatorInterface $translator
     * @param FieldHelper $fieldHelper
     */
    public function __construct(
        ManagerRegistry $managerRegistry,
        ValidatorInterface $validator,
        TranslatorInterface $translator,
        FieldHelper $fieldHelper
    ) {
        $this->managerRegistry = $managerRegistry;
        $this->validator = $validator;
        $this->translator = $translator;
        $this->fieldHelper = $fieldHelper;
    }

    /**
     * @param string $entityClass
     * @return EntityManager
     * @throws LogicException
     */
    public function getEntityManager($entityClass)
    {
        $entityManager = $this->managerRegistry->getManagerForClass($entityClass);
        if (!$entityManager) {
            throw new LogicException(
                sprintf('Can\'t find entity manager for %s', $entityClass)
            );
        }

        return $entityManager;
    }

    /**
     * @param object $basicEntity
     * @param object $importedEntity
     * @param array $excludedProperties
     * @throws InvalidArgumentException
     */
    public function importEntity($basicEntity, $importedEntity, array $excludedProperties = array())
    {
        $basicEntityClass = ClassUtils::getClass($basicEntity);
        if ($basicEntityClass != ClassUtils::getClass($importedEntity)) {
            throw new InvalidArgumentException('Basic and imported entities must be instances of the same class');
        }

        $entityMetadata = $this->getEntityManager($basicEntityClass)->getClassMetadata($basicEntityClass);
        $importedEntityProperties = array_diff(
            array_merge(
                $entityMetadata->getFieldNames(),
                $entityMetadata->getAssociationNames()
            ),
            $excludedProperties
        );

        foreach ($importedEntityProperties as $propertyName) {
            $importedValue = $this->fieldHelper->getObjectValue($importedEntity, $propertyName);

            // collections MUST override existing values to avoid dirty data
            if ($importedValue instanceof Collection) {
                /** @var \ReflectionProperty $reflectionProperty */
                $reflectionProperty = $entityMetadata->getReflectionProperty($propertyName);
                $reflectionProperty->setAccessible(true); // just to make sure
                $reflectionProperty->setValue($basicEntity, $importedValue);
            } else {
                $this->fieldHelper->setObjectValue($basicEntity, $propertyName, $importedValue);
            }
        }
    }

    /**
     * Validate entity, returns list of errors or null
     *
     * @param object $entity
     * @param null   $groups
     *
     * @return array|null
     */
    public function validateEntity($entity, $groups = null)
    {
        $violations = $this->validator->validate($entity, $groups);
        if (count($violations)) {
            $errors = array();
            /** @var ConstraintViolationInterface $violation */
            foreach ($violations as $violation) {
                $errors[] = sprintf(sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage()));
            }
            return $errors;
        }

        return null;
    }

    /**
     * @param array $validationErrors
     * @param ContextInterface $context
     * @param string|null $errorPrefix
     */
    public function addValidationErrors(array $validationErrors, ContextInterface $context, $errorPrefix = null)
    {
        if (null === $errorPrefix) {
            $errorPrefix = $this->translator->trans(
                'oro.importexport.import.error %number%',
                array(
                    '%number%' => $context->getReadOffset()
                )
            );
        }
        foreach ($validationErrors as $validationError) {
            $context->addError($errorPrefix . ' ' . $validationError);
        }
    }
}
