<?php
namespace NoSql;

abstract class CNoSqlModel extends \CModel
{
    public static $db;
    private       $_id              = null;
    private       $_isNewRecord     = true;
    private       $_isDeletedRecord = false;
    private       $_connection      = null;
    private       $_attributes      = null;
    private       $_initialValues   = [];
    private       $_changeLog       = [];
    private       $_embedded        = [];
    private       $_config          = [];

    protected static $MODEL_TB = null;
    protected static $MODEL_DB = null;
    protected static $MODEL_PK = null;

    /******************************************************************************************
     * CNoSqlModel constructor.
     */
    public function __construct()
    {
        $this->_isNewRecord = true;
        $this->setDefaults();
        $this->init();
        $this->attachBehaviors($this->behaviors());
        $this->afterConstruct();
        $this->seedEmbeddables();
    }

    final private function seedEmbeddables()
    {
        $e = $this->embeddedDocs();

        if (is_array($e)) {
            foreach ($e as $n => $v) {
                if ($v == 'class') {
                    $this->{$n} = new $n;
                }
                elseif ($v == 'object') {
                    $this->{$n} = new \stdClass();
                }
                elseif ($v == 'array') {
                    $this->{$n} = [];
                }
            }
        }
    }

    /******************************************************************************************
     *
     */
    abstract public function init();

    /********************************************************************
     * @return string
     */
    public function objectType()
    {
        return $this->className();
    }

    /********************************************************************
     * @return mixed|null
     */
    public function objectId()
    {
        return (string)$this->{$this->primaryKey()};
    }

    /******************************************************************************************
     *
     */
    public function isNewRecord()
    {
        return (bool)$this->_isNewRecord;
    }

    /******************************************************************************************************
     * @param array $attributes
     *
     * @return static
     */
    public function instantiate($attributes = [])
    {
        $class = get_class($this);

        /**
         * @var $model static
         */
        $model = new $class(null);
        $model->setDefaults();

        return $model;
    }

    /******************************************************************************************************
     * RETURN AN ARRAY OF DEFINED EMBEDDED DOCUMENTS.
     * THE KEY SHOULD BE EITHER THE NAME OF THE ARRAY, OR OBJECT,
     * OR THE ACTUAL CLASS NAME IF THE EMBEDDED DOC IS BASED ON A MODEL.
     *
     * MODELS MUST HAVE A 'getAttributes()' and 'setAttributes' METHODS, WHICH RETURNS AND SETS
     * NAME VALUE PAIRS.
     * SUGGEST TO USE AN INSTANCE OF CMODEL OR CLASS THAT EXTENDS IT.
     * [
     *   "{ClassName}" => "class",
     *   "{ArrayName}" => "array",
     *   "{ObjectName}" => "object",
     * ]
     *
     * @return array
     */
    public function embeddedDocs()
    {
        return array();
    }

    /******************************************************************************************************
     * @param $docName
     *
     * @return array|mixed|\stdClass
     * @throws \CustHttpException
     */
    public function embeddedDoc($docName)
    {
        $docModels = $this->embeddedDocs();

        if (!isset($this->_embedded)) {
            throw new \CustHttpException(500, "The class: " . get_called_class()
                                              . ' does not support _embedded documents.');
        }

        if (isset($docModels[ $docName ])) {
            if (isset($this->_embedded[ $docName ])) {
                return $this->_embedded[ $docName ];
            }
            elseif (strtolower($docModels[ $docName ]) == 'class') {
                return new $docName;
            }
            elseif (strtolower($docModels[ $docName ]) == 'array') {
                return [];
            }
            else {
                return new \stdClass();
            }
        }
        throw new \CustHttpException(500, "Could not find a proper definition for the requested embedded doc for: "
                                          . get_called_class());
    }

    /******************************************************************************************************
     * @return $this
     */
    public function setDefaults()
    {
        if (method_exists($this, 'attributeDefaults')) {
            $defaults = $this->attributeDefaults();
            foreach ($defaults as $n => $v) {
                if (property_exists($this, $n)) {
                    $this->{$n} = $v;
                }
            }
        }

        return $this;
    }

    /******************************************************************************************************
     * Increase field value and save item
     *
     * @param     $fieldName
     * @param int $cnt
     */
    public function increment($fieldName, $cnt = 1)
    {
        if (isset($this->{$fieldName}) || in_array($fieldName, $this->attributeNames())) {
            $this->{$fieldName} = ((int)$this->{$fieldName}) + ((int)$cnt);
            $this->update();
        }
    }

    /******************************************************************************************************
     * Decrease field value and save
     *
     * @param     $fieldName
     * @param int $cnt
     */
    public function decrement($fieldName, $cnt = 1)
    {
        if (isset($this->{$fieldName}) || in_array($fieldName, $this->attributeNames())) {
            $this->{$fieldName} = ((int)$this->{$fieldName}) - ((int)$cnt);
            $this->update();
        }
    }

    /******************************************************************************************
     * RETURN AN ARRAY OF DEFAULT VALUES FOR ATTRIBUTES DEFINED IN THE MODEL
     * @return array
     */
    abstract public function attributeDefaults();

    /******************************************************************************************
     * RETURN AN ARRAY OF DESCRIPTIONS FOR ATTRIBUTES DEFINED IN THE MODEL
     * @return array
     */
    abstract public function attributeDescriptions();

    /******************************************************************************************
     * GET A FORMATED CONNECTION STRING FOR THE REMOTE DATA STORE.
     * @return mixed
     */
    abstract public function getDbConnectionString();

    /******************************************************************************************
     * RETURN THE ACTUALLY DATASTORE CONNECTION OBJECT/RESOURCE. IF THE CONNECTION HAS NOT
     * BEEN INITIALIZED YET, IE. THE OBJECT DOESN'T EXIST YET, ATTEMPT TO CONNECT TO THE
     * DATASTORE.
     * @return mixed
     */
    abstract public function getDbConnection();

    /******************************************************************************************
     * @param null $attributeName
     *
     * @return mixed|null
     */
    public function getAttributeDescription($attributeName = null)
    {
        $descriptions = (array)$this->attributeDescriptions();
        if (isset($descriptions[ $attributeName ])) {
            return $descriptions[ $attributeName ];
        }

        return null;
    }

    /******************************************************************************************
     * RETURN THE DEFAULT VALUE OF AN ATTRIBUTE IN THE MODEL
     *
     * @param $attributeName
     *
     * @return mixed|null
     */
    function getAttributeDefault($attributeName)
    {
        $defaults = (array)$this->attributeDefaults();
        if (isset($defaults[ $attributeName ])) {
            return $defaults[ $attributeName ];
        }

        return null;
    }


    /******************************************************************************************
     * @return mixed
     */
    //abstract public function onAfterFind();

    /******************************************************************************************
     * @return mixed
     */
    //abstract public function onAfterSave();

    /******************************************************************************************
     * POPULATE A SINGLE MODEL AFTER FINDING IT
     *
     * @param      $attributes
     * @param bool $callAfterFind
     *
     * @return mixed
     */
    abstract public function populateRecord($attributes, $callAfterFind = true);

    /******************************************************************************************
     * POPULATE MODELS AFTER FINDING THEM USING FIND() FUNCTIONS
     *
     * @param      $data
     * @param bool $callAfterFind
     * @param null $index
     */
    abstract public function populateRecords($data, $callAfterFind = true, $index = null);

    /******************************************************************************************
     *
     */
    abstract public function refresh();

    /******************************************************************************************
     *
     */
    abstract public function insert();

    /******************************************************************************************
     *
     */
    abstract public function update();

    /******************************************************************************************
     * DELETE THE CURRENTLY INSTANTIATED RECORD
     */
    abstract public function delete();

    /******************************************************************************************
     * FIND A RECORD BASED ON PARAMS AND RETURN AN INSTANTIATED MODEL WHAT EXTENDS
     * THE NOSQL MODEL
     */
    abstract public function find($filter);

    /******************************************************************************************
     * FIND A RECORD/DOCUMENT IN THE DATA STORE USING THE PROVIDED DOCUMENT OR OBJECT ID
     *
     * @param $id
     *
     * @return mixed
     */
    abstract public function findById($id);

    /******************************************************************************************
     * FIND ON OR MORE RECORDS BASED ON GIVEN PARAMS AND RETURN AN AN ARRAY OF
     * INSTANTIATED MODELS WHAT EXTENDS THE NOSQL MODEL
     */
    abstract public function findAll($filter);

    /******************************************************************************************
     * FIND ON OR MORE RECORDS BASED ON GIVEN PARAMS AND RETURN AN AN ARRAY OF
     * INSTANTIATED MODELS WHAT EXTENDS THE NOSQL MODEL
     */
    abstract public function count($filter);

    /******************************************************************************************
     * EXPORT THE CURRENT MODEL IN A FORMAT THAT CORRESPONDS TO THE PASSED PARAMETER
     *
     * @param string $dataType
     */
    abstract public function export($dataType = '');

    /******************************************************************************************
     *
     */
    public function logChanges()
    {
    }

    /******************************************************************************************
     * @return null
     */
    public function dbConfig()
    {
        return static::modelDbName();
    }

    /******************************************************************************************
     * RETURN THE DB NAME WHERE THE RECORDS FOR THE CURRENT MODEL ARE STORED
     * @return null
     */
    public function dbName()
    {
        return static::modelDbName();
    }

    /******************************************************************************************
     * RETURN THE PRIMARY KEY ALIAS. IN NO SQL SOLUTIONS, STORED DOCUMENTS ARE TYPICALL GIVEN
     * A UNIQUE OID BY THE STORAGE ENGINE.
     * @return string
     */
    public function primaryKey()
    {
        return static::modelPkName();
    }

    /******************************************************************************************
     * TYPE IS TYPICALLY IS AN ALIAS FOR modelName()
     * @return string
     */
    public function type()
    {
        return $this->modelName();
    }

    /******************************************************************************************
     * RETURN THE NAME OF THE CURRENT MODEL/CLASS
     * @return string
     */
    public function modelName()
    {
        return get_called_class();
    }

    /******************************************************************************************
     * RETURN THE TABLE/COLLECTION NAME WHERE THE RECORDS FOR THE CURRENT MODEL ARE STORED
     * @return null
     */
    public function tableName()
    {
        return static::modelTableName();
    }

    /******************************************************************************************
     * RETURN THE DB NAME WHERE THE RECORDS FOR THE CURRENT MODEL ARE STORED
     * @return null
     */
    public static function modelDbName()
    {
        return static::$MODEL_DB;
    }

    /******************************************************************************************
     * RETURN THE PRIMARY KEY ALIAS ASSOCIATED WITH THE MODEL
     * @return null
     */
    public static function modelPkName()
    {
        return static::$MODEL_PK;
    }

    /******************************************************************************************
     * RETURN THE TABLE/COLLECTION NAME ASSOCIATED WITH THE CURRENT MODEL
     * @return null
     */
    public static function modelTableName()
    {
        return static::$MODEL_TB;
    }

    /******************************************************************************************
     * RETURN THE NAME OF THE INSTANTIATED MODEL EXTENDING THE NOSQL ABSTRACT MODEL
     * @return string
     */
    public static function className()
    {
        return get_called_class();
    }

    /******************************************************************************************
     * @return mixed
     */
    public static function browse()
    {
    }

    /******************************************************************************************
     * RETURN AN INSTANTIATED MODEL OF THE GIVEN CLASS or THE CURRENT CLASS
     *
     * @param null $class
     *
     * @return mixed
     * @throws \Exception
     */
    public static function model($class = null)
    {
        if (class_exists($class)) {
            return new $class;
        }
        elseif ($class == null) {
            $c = get_called_class();

            return new $c;
        }
        throw new \Exception();
    }
}