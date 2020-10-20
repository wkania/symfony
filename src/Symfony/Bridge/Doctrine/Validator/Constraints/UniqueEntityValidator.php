<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Validator\Constraints;

use Doctrine\ORM\Mapping\MappingException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Unique Entity Validator checks if one or a set of fields contain unique values.
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 */
class UniqueEntityValidator extends ConstraintValidator
{
    private ManagerRegistry $registry;

    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param object $entity
     *
     * @throws UnexpectedTypeException
     * @throws ConstraintDefinitionException
     */
    public function validate(mixed $entity, Constraint $constraint)
    {
        if (!$constraint instanceof UniqueEntity) {
            throw new UnexpectedTypeException($constraint, UniqueEntity::class);
        }

        if (!\is_array($constraint->fields) && !\is_string($constraint->fields)) {
            throw new UnexpectedTypeException($constraint->fields, 'array');
        }

        if (null !== $constraint->errorPath && !\is_string($constraint->errorPath)) {
            throw new UnexpectedTypeException($constraint->errorPath, 'string or null');
        }

        $fields = (array) $constraint->fields;

        if (0 === \count($fields)) {
            throw new ConstraintDefinitionException('At least one field has to be specified.');
        }

        if (null === $entity) {
            return;
        }

        if (!\is_object($entity)) {
            throw new UnexpectedValueException($entity, 'object');
        }

        $entityClass = \get_class($entity);

        if ($constraint->em) {
            $em = $this->registry->getManager($constraint->em);

            if (!$em) {
                throw new ConstraintDefinitionException(sprintf('Object manager "%s" does not exist.', $constraint->em));
            }
        } else {
            $em = $this->registry->getManagerForClass($entityClass);

            if (!$em) {
                throw new ConstraintDefinitionException(sprintf('Unable to find the object manager associated with an entity of class "%s".', get_debug_type($entity)));
            }
        }

        try {
            $repository = $em->getRepository($entityClass);
            $isEntity = true;
        }catch (MappingException $e) {
            $isEntity = false;
        }

        if (null !== $constraint->entityClass) {
            /* Retrieve repository from given entity name.
             * We ensure the retrieved repository can handle the entity
             * by checking the entity is the same, or subclass of the supported entity.
             */
            $repository = $em->getRepository($constraint->entityClass);
            $supportedClass = $repository->getClassName();

            if ($isEntity && !$entity instanceof $supportedClass) {
                $class = $em->getClassMetadata($entityClass);
                throw new ConstraintDefinitionException(sprintf('The "%s" entity repository does not support the "%s" entity. The entity should be an instance of or extend "%s".', $constraint->entityClass, $class->getName(), $supportedClass));
            }
            $entityClass = $constraint->entityClass;
        }

        $class = $em->getClassMetadata($entityClass);

        $criteria = [];
        $hasNullValue = false;

        $fieldValues = $this->getFieldValues($entity, $class, $fields, $isEntity);

        foreach ($fieldValues as $entityFieldName => $fieldValue) {
            if (null === $fieldValue) {
                $hasNullValue = true;
            }

            if ($constraint->ignoreNull && null === $fieldValue) {
                continue;
            }

            $criteria[$entityFieldName] = $fieldValue;

            if (null !== $criteria[$entityFieldName] && $class->hasAssociation($entityFieldName)) {
                /* Ensure the Proxy is initialized before using reflection to
                 * read its identifiers. This is necessary because the wrapped
                 * getter methods in the Proxy are being bypassed.
                 */
                $em->initializeObject($criteria[$entityFieldName]);
            }
        }

        // validation doesn't fail if one of the fields is null and if null values should be ignored
        if ($hasNullValue && $constraint->ignoreNull) {
            return;
        }

        // skip validation if there are no criteria (this can happen when the
        // "ignoreNull" option is enabled and fields to be checked are null
        if (empty($criteria)) {
            return;
        }

        $arguments = [$criteria];

        /* If the default repository method is used, it is always enough to retrieve at most two entities because:
         * - No entity returned, the current entity is definitely unique.
         * - More than one entity returned, the current entity cannot be unique.
         * - One entity returned the uniqueness depends on the current entity.
         */
        if ('findBy' === $constraint->repositoryMethod) {
            $arguments = [$criteria, null, 2];
        }

        $result = $repository->{$constraint->repositoryMethod}(...$arguments);

        if ($result instanceof \IteratorAggregate) {
            $result = $result->getIterator();
        }

        /* If the result is a MongoCursor, it must be advanced to the first
         * element. Rewinding should have no ill effect if $result is another
         * iterator implementation.
         */
        if ($result instanceof \Iterator) {
            $result->rewind();
            if ($result instanceof \Countable && 1 < \count($result)) {
                $result = [$result->current(), $result->current()];
            } else {
                $result = $result->valid() && null !== $result->current() ? [$result->current()] : [];
            }
        } elseif (\is_array($result)) {
            reset($result);
        } else {
            $result = null === $result ? [] : [$result];
        }

        /* If no entity matched the query criteria or a single entity matched,
         * which is the same as the entity being validated, the criteria is
         * unique.
         */
        if (!$result || (1 === \count($result) && current($result) === $entity)) {
            return;
        }

        /* If a single entity matched the query criteria, which is the same as
         * the entity being updated by validated object, the criteria is unique.
         */
        if (!$isEntity && !empty($constraint->identifierFieldNames) && 1 === \count($result)) {
            if (!\is_array($constraint->identifierFieldNames) && !\is_string($constraint->identifierFieldNames)) {
                throw new UnexpectedTypeException($constraint->identifierFieldNames, 'array');
            }

            $identifierFieldNames = (array) $constraint->identifierFieldNames;

            $fieldValues = $this->getFieldValues($entity, $class, $identifierFieldNames);
            if (array_values($class->getIdentifierFieldNames()) != array_values($identifierFieldNames)) {
                throw new ConstraintDefinitionException(
                    sprintf('The "%s" entity identifier field names should be "%s", not "%s".', $constraint->entityClass, implode(', ', $class->getIdentifierFieldNames()), implode(', ', $constraint->identifierFieldNames))
                );
            }

            $entityMatched = true;

            foreach ($identifierFieldNames as $identifierFieldName) {
                $field = new \ReflectionProperty($constraint->entityClass, $identifierFieldName);
                if (!$field->isPublic()) {
                    $field->setAccessible(true);
                }

                $propertyValue = $this->getPropertyValue($constraint->entityClass, $identifierFieldName, current($result));
                if ($fieldValues[$identifierFieldName] !== $propertyValue) {
                    $entityMatched = false;
                    break;
                }
            }

            if ($entityMatched) {
                return;
            }
        }

        $errorPath = $constraint->errorPath ?? current($fields);
        $invalidValue = $criteria[$errorPath] ?? $criteria[current($fields)];

        $this->context->buildViolation($constraint->message)
            ->atPath($errorPath)
            ->setParameter('{{ value }}', $this->formatWithIdentifiers($em, $class, $invalidValue))
            ->setInvalidValue($invalidValue)
            ->setCode(UniqueEntity::NOT_UNIQUE_ERROR)
            ->setCause($result)
            ->addViolation();
    }

    private function formatWithIdentifiers(ObjectManager $em, ClassMetadata $class, mixed $value)
    {
        if (!\is_object($value) || $value instanceof \DateTimeInterface) {
            return $this->formatValue($value, self::PRETTY_DATE);
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if ($class->getName() !== $idClass = \get_class($value)) {
            // non unique value might be a composite PK that consists of other entity objects
            if ($em->getMetadataFactory()->hasMetadataFor($idClass)) {
                $identifiers = $em->getClassMetadata($idClass)->getIdentifierValues($value);
            } else {
                // this case might happen if the non unique column has a custom doctrine type and its value is an object
                // in which case we cannot get any identifiers for it
                $identifiers = [];
            }
        } else {
            $identifiers = $class->getIdentifierValues($value);
        }

        if (!$identifiers) {
            return sprintf('object("%s")', $idClass);
        }

        array_walk($identifiers, function (&$id, $field) {
            if (!\is_object($id) || $id instanceof \DateTimeInterface) {
                $idAsString = $this->formatValue($id, self::PRETTY_DATE);
            } else {
                $idAsString = sprintf('object("%s")', \get_class($id));
            }

            $id = sprintf('%s => %s', $field, $idAsString);
        });

        return sprintf('object("%s") identified by (%s)', $idClass, implode(', ', $identifiers));
    }

    public function getFieldValues($object,  ClassMetadata $class, array $fields, bool $isEntity = false): array
    {
        if (!$isEntity) {
            $reflectionObject = new \ReflectionObject($object);
        }

        $fieldValues = [];
        $objectClass = \get_class($object);

        foreach ($fields as $objectFieldName => $entityFieldName) {
            if (!$class->hasField($entityFieldName) && !$class->hasAssociation($entityFieldName)) {
                throw new ConstraintDefinitionException(sprintf('The field "%s" is not mapped by Doctrine, so it cannot be validated for uniqueness.', $entityFieldName));
            }

            $fieldName = is_int($objectFieldName) ? $entityFieldName : $objectFieldName;
            if (!$isEntity) {
                if (!$reflectionObject->hasProperty($fieldName)) {
                    throw new ConstraintDefinitionException(sprintf('The field "%s" is not a property of class "%s".', $fieldName, $objectClass));
                }
            }

            $fieldValues[$entityFieldName] = $this->getPropertyValue($objectClass, $fieldName, $object);
        }

        return $fieldValues;
    }

    public function getPropertyValue($class, $name, $object)
    {
        $property = new \ReflectionProperty($class, $name);
        if (!$property->isPublic()) {
            $property->setAccessible(true);
        }

        return $property->getValue($object);
    }
}
