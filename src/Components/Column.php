<?php

namespace Zenderator\Components;

use Gone\Inflection\Inflect;
use Zenderator\Exception\DBTypeNotTranslatedException;
use Zenderator\Zenderator;

class Column extends Entity
{
    /** @var Model */
    protected $model;

    protected $className;
    protected $field;
    protected $dbType;
    protected $maxLength;
    protected $isUnsigned = false;
    protected $maxFieldLength;
    protected $maxDecimalPlaces;
    protected $permittedValues;
    protected $defaultValue;
    protected $isAutoIncrement = false;
    protected $isUnique = false;
    protected $isNullable = true;
    protected $structure = null;
    /** @var RelatedModel[] */
    protected $relatedObjects = [];
    /** @var RelatedModel[] */
    protected $remoteObjects = [];

    /**
     * @return self
     */
    public static function Factory(Zenderator $zenderator)
    {
        return parent::Factory($zenderator);
    }

    /**
     * @return Model
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * @param Model $model
     *
     * @return Column
     */
    public function setModel(Model $model)
    {
        $this->model = $model;
        return $this;
    }

    /**
     * @return bool
     */
    public function isUnsigned(): bool
    {
        return $this->isUnsigned;
    }

    /**
     * @param bool $isUnsigned
     *
     * @return Column
     */
    public function setIsUnsigned(bool $isUnsigned): Column
    {
        $this->isUnsigned = $isUnsigned;
        return $this;
    }

    /**
     * @return bool
     */
    public function isAutoIncrement(): bool
    {
        return $this->isAutoIncrement;
    }

    /**
     * @param bool $isAutoIncrement
     *
     * @return Column
     */
    public function setIsAutoIncrement(bool $isAutoIncrement): Column
    {
        $this->isAutoIncrement = $isAutoIncrement;
        return $this;
    }

    /**
     * @return bool
     */
    public function isUnique(): bool
    {
        return $this->isUnique;
    }

    /**
     * @param bool $isUnique
     *
     * @return Column
     */
    public function setIsUnique(bool $isUnique): Column
    {
        $this->isUnique = $isUnique;
        return $this;
    }

    /**
     * @return bool
     */
    public function isNullable(): bool
    {
        return $this->isNullable;
    }

    /**
     * @param bool $isUnique
     *
     * @return Column
     */
    public function setIsNullable(bool $isNullable): Column
    {
        $this->isNullable = $isNullable;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getPhpType()
    {
        $type = self::ConvertColumnType($this->getDbType());
        if (empty($type)) {
            throw new DBTypeNotTranslatedException("Type not translated: {$this->getDbType()}");
        }
        return $type;
    }

    public function getPropertyName()
    {
        return $this->getField();
        return $this->transField2Property->transform($this->getField());
    }

    /**
     * @return mixed
     */
    public function getField()
    {
        return $this->field;
    }

    /**
     * @param mixed $field
     *
     * @return Column
     */
    public function setField($field)
    {
        $this->field = $field;
        return $this;
    }

    public function getPropertyFunction()
    {
        return $this->transCamel2Studly->transform($this->getField());
    }

    /**
     * @return mixed
     */
    public function getMaxDecimalPlaces()
    {
        return $this->maxDecimalPlaces;
    }

    /**
     * @param mixed $maxDecimalPlaces
     *
     * @return Column
     */
    public function setMaxDecimalPlaces($maxDecimalPlaces)
    {
        $this->maxDecimalPlaces = $maxDecimalPlaces;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getDefaultValue()
    {
        return $this->defaultValue;
    }

    /**
     * @param mixed $defaultValue
     *
     * @return Column
     */
    public function setDefaultValue($defaultValue)
    {
        $this->defaultValue = $defaultValue;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getMaxLength()
    {
        if($this->maxLength === null && $this->getDbType() === "datetime"){
            return strlen("0000-00-00 00:00:00");
        }
        return $this->maxLength;
    }

    /**
     * @param mixed $maxLength
     *
     * @return Column
     */
    public function setMaxLength($maxLength)
    {
        $this->maxLength = $maxLength;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getMaxFieldLength()
    {
        return $this->maxFieldLength;
    }

    /**
     * @param mixed $maxFieldLength
     *
     * @return Column
     */
    public function setMaxFieldLength($maxFieldLength)
    {
        $this->maxFieldLength = $maxFieldLength;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getDbType()
    {
        return $this->dbType;
    }

    /**
     * @param mixed $dbType
     *
     * @return Column
     */
    public function setDbType($dbType, $structure = null)
    {
        $this->dbType = $dbType;
        $this->structure = $structure;
        return $this;
    }

    public function getStructure(){
        return $this->structure;
    }

    public static function convertColumnType($dbType)
    {
        $type = null;
        switch ($dbType) {
            case 'float':
            case 'decimal':
            case 'double':
                $type = 'float';
                break;
            case 'bit':
            case 'int':
            case 'bigint':
            case 'tinyint':
            case 'smallint':
                $type = 'int';
                break;
            case 'varchar':
            case 'smallblob':
            case 'blob':
            case 'longblob':
            case 'smalltext':
            case 'text':
            case 'longtext':
            case 'json':
            case 'password':
                $type = 'string';
                break;
            case 'enum':
                $type = 'string';
                break;
            case 'datetime':
                $type = 'string';
                break;
            default:
                break;
        }
        return $type;
    }

    /**
     * @return mixed
     */
    public function getPermittedValues()
    {
        return $this->permittedValues;
    }

    /**
     * @param mixed $permittedValues
     *
     * @return Column
     */
    public function setPermittedValues($permittedValues)
    {
        if(is_array($permittedValues)) {
            sort($permittedValues);
        }
        $this->permittedValues = $permittedValues;
        return $this;
    }

    /**
     * @param RelatedModel $relatedModel
     *
     * @return $this
     */
    public function addRelatedObject(RelatedModel $relatedModel)
    {
        $this->relatedObjects[] = $relatedModel;
        return $this;
    }

    /**
     * @param RelatedModel $relatedModel
     *
     * @return $this
     */
    public function addRemoteObject(RelatedModel $relatedModel)
    {
        $this->remoteObjects[] = $relatedModel;
        return $this;
    }

    public function hasRelatedObjects(): bool
    {
        return count($this->relatedObjects) > 0;
    }

    public function hasRemoteObjects(): bool
    {
        return count($this->remoteObjects) > 0;
    }

    /**
     * @return mixed
     */
    public function getClassName()
    {
        return $this->className;
    }

    /**
     * @param mixed $className
     *
     * @return Column
     */
    public function setClassName($className)
    {
        $this->className = $className;
        return $this;
    }

    /**
     * @return RelatedModel[]
     */
    public function getRelatedObjects(): array
    {
        return $this->relatedObjects;
    }

    /**
     * @return RelatedModel[]
     */
    public function getRemoteObjects(): array
    {
        return $this->remoteObjects;
    }

    public static function cleanName($name)
    {
        return ucfirst(preg_replace('/Id$/', '', $name));
    }

    public function getMethodFieldName()
    {
        $name = ucfirst($this->getField());
        if ($name == "Id") {
            $name = "ID";
        }
        return $name;
    }

    public function getRelatedData()
    {
        $data = [];
        foreach ($this->getRelatedObjects() as $relatedObject) {
            $data[] = [
                "class" => [
                    "name"           => $relatedObject->getRemoteClass(),
                    "nameLC"         => lcfirst($relatedObject->getRemoteClass()),
                    "plural"         => Inflect::pluralize($relatedObject->getRemoteClass()),
                    "variable"       => lcfirst($relatedObject->getRemoteClass()),
                    "variablePlural" => lcfirst(Inflect::pluralize($relatedObject->getRemoteClass())),
                ],
                "field" => [
                    "local"   => [
                        "name"           => $this->getField(),
                        "variable"       => $this->getField(),
                        "variablePlural" => Inflect::pluralize($this->getField()),
                    ],
                    "related" => [
                        "name"             => $relatedObject->getRemoteBoundColumn(),
                        "variable"         => $relatedObject->getRelatedVariableName(),
                        "variableUC"       => ucfirst($relatedObject->getRelatedVariableName()),
                        "variablePlural"   => Inflect::pluralize($relatedObject->getRelatedVariableName()),
                        "variablePluralUC" => ucfirst(Inflect::pluralize($relatedObject->getRelatedVariableName())),
                    ],
                ],
            ];
        }
        return $data;
    }

    public function getRemoteData()
    {
        $data = [];
        foreach ($this->getRemoteObjects() as $remoteObject) {
            $variable = $remoteObject->getLocalClass();
            if(strtolower($variable) !== strtolower($remoteObject->getRemoteClass())){
                $variable = preg_replace("/{$remoteObject->getRemoteClass()}/i", "", $variable);
            }
            $data[] = [
                "class" => [
                    "name"           => $remoteObject->getLocalClass(),
                    "nameLC"         => lcfirst($remoteObject->getLocalClass()),
                    "plural"         => Inflect::pluralize($remoteObject->getLocalClass()),
                    "variable"       => lcfirst($variable),
                    "variablePlural" => lcfirst(Inflect::pluralize($variable)),
                    "trueClass"      => $remoteObject->getLocalClassPreMap(),
                ],
                "field" => [
                    "local"  => [
                        "name"           => $this->getField(),
                        "variable"       => $this->getField(),
                        "variablePlural" => Inflect::pluralize($this->getField()),
                    ],
                    "remote" => [
                        "name"             => $remoteObject->getLocalBoundColumn(),
                        "variable"         => $remoteObject->getRemoteVariableName(),
                        "variableUC"       => ucfirst($remoteObject->getRemoteVariableName()),
                        "variablePlural"   => Inflect::pluralize($remoteObject->getRemoteVariableName()),
                        "variablePluralUC" => ucfirst(Inflect::pluralize($remoteObject->getRemoteVariableName())),
                    ],
                ],
            ];
        }
        return $data;
    }

    public function getPropertyData()
    {
        $data = [
            "name"      => $this->getMethodFieldName(),
            "type"      => $this->getDbType(),
            "options"   => $this->getPermittedValues(),
            "phpType"   => $this->getPhpType(),
            "unique"    => $this->isUnique(),
            "nullable"  => $this->isNullable(),
            "length"    => intval($this->getMaxLength()),
            "related"   => $this->getRelatedData(),
            "remote"    => $this->getRemoteData(),
            "className" => $this->getClassName(),
            "structure" => $this->getStructure(),
        ];

        return $data;
    }
}
