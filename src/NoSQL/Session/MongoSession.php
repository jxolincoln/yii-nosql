<?php
/*****************************************************************************************************
 * Use mongo to store php session.
 * Sessions have been customized to ATTEMPT to store UserID with the session.
 * This makes it easier to track users and force a single session on a single device if needed.
 */
namespace NoSQL\Session;

class MongoSession extends \CHttpSession
{
    public $autoStart = true;

    public $connectionID;

    /**
     * @var string the name of the DB table to store session content.
     * Note, if {@link autoCreateSessionTable} is false and you want to create the DB table manually by
     * yourself, you need to make sure the DB table is of the following structure:
     * <pre>
     * (id CHAR(32) PRIMARY KEY, expire INTEGER, data BLOB)
     * </pre>
     * @see autoCreateSessionTable
     */
    public $sessionTableName = 'YiiSession';

    /**
     * @var boolean whether the session DB table should be automatically created if not exists. Defaults to
     *      true.
     * @see sessionTableName
     */
    public $autoCreateSessionTable = true;

    /**
     * @var null id of user associated with session
     */
    public $UserID = '';

    /**
     * @var null WILL HOLD MONGO SESSION
     */
    private $_connection = null;

    /******************************************************************************
     * @return null
     */
    public function getUserId()
    {
        return $this->UserID;
    }

    /******************************************************************************
     * @param $userId
     */
    public function setUserId($userId)
    {
        $this->UserID = $userId;
    }

    /******************************************************************************
     * @return bool
     */
    public function getUseCustomStorage()
    {
        return true;
    }

    /******************************************************************************
     * @return \MongoDB\Client|null
     * @throws \CHttpException
     */
    public function getDbConnection()
    {
        try {
            if (empty($this->_connection)) {
                $this->_connection = new \MongoDB\Client($this->getDbConnectionString());
            }
        } catch (\Exception $e) {
            throw new \CHttpException(500, 'Could not connect to requested datastore: ' . $e->getMessage());
        }

        return $this->_connection;
    }

    /******************************************************************************
     * @return string
     * @throws \CHttpException
     */
    public function getDbConnectionString()
    {
        $datastores = \Yii::app()->params->datastores;

        if (!empty($datastores)) {
            $datastores = (array)$datastores;
            if (isset($datastores[constant($this->connectionID)])) {
                $datastore = (object)$datastores[constant($this->connectionID)];
            } else {
                throw new \CHttpException(500, "Could not find definition for datastore: "
                                               . constant($this->connectionID));
            }
        } else {
            throw new \CHttpException(500, "No datastores have been defined.");
        }

        $connectionString = "mongodb://{$datastore->ds_user}:{$datastore->ds_pass}@{$datastore->ds_host}:{$datastore->ds_port}";

        return $connectionString;
    }

    /******************************************************************************
     * Session open handler.
     * This method should be overridden if {@link useCustomStorage} is set true.
     * Do not call this method directly.
     *
     * @param string $savePath
     * @param string $sessionName
     *
     * @return bool
     * @throws \CHttpException
     */
    public function openSession($savePath, $sessionName)
    {
        $dbConn    = $this->getDbConnection();
        $tableName = $this->sessionTableName;
        $db        = $dbConn->selectDatabase(constant($this->connectionID));

        try {
            $db->{$tableName}->deleteOne(['expire' => ['$lt' => time()]]);
        } catch (\Exception $e) {
            throw new \CHttpException(500, "Could not connect to requested datastore");
        }

        return true;
    }

    /******************************************************************************
     * Session close handler.
     * This method should be overridden if {@link useCustomStorage} is set true.
     * Do not call this method directly.
     * @return boolean whether session is closed successfully
     */
    public function closeSession()
    {
        return true;
    }

    /******************************************************************************
     * Session read handler.
     * This method should be overridden if {@link useCustomStorage} is set true.
     * Do not call this method directly.
     *
     * @param string $id session ID
     *
     * @return string the session data
     */
    public function readSession($id)
    {
        try {
            $dbConn    = $this->getDbConnection();
            $tableName = $this->sessionTableName;
            $db        = $dbConn->selectDatabase(constant($this->connectionID));
            $now       = time();

            $result = $db->{$tableName}->findOne(['session_id' => $id]);
            if ($result) {
                $record = $result->jsonSerialize();

                return $record->data;
            }

            return '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /******************************************************************************
     * Session write handler.
     * This method should be overridden if {@link useCustomStorage} is set true.
     * Do not call this method directly.
     *
     * @param string $id session ID
     * @param string $data session data
     *
     * @return boolean whether session write is successful
     */
    public function writeSession($id, $data)
    {
        $dbConn    = $this->getDbConnection();
        $tableName = $this->sessionTableName;
        $coll      = $dbConn->selectCollection(constant($this->connectionID), $tableName);

        $sessionData = array(
            'session_id'  => $id,
            'UserID'      => $this->getUserId(),
            'data'        => $data,
            'last_active' => time(),
            'expire'      => time() + $this->getTimeout()
        );

        if ($coll->count(["session_id" => $id])) {
            $updateResult = $coll->updateOne(array("session_id" => $id), array('$set' => $sessionData));
        } else {
            $inserted = $coll->insertOne($sessionData);
        }

        return true;
    }

    /******************************************************************************
     * Session destroy handler.
     * This method should be overridden if {@link useCustomStorage} is set true.
     * Do not call this method directly.
     *
     * @param string $id session ID
     *
     * @return boolean whether session is destroyed successfully
     */
    public function destroySession($id)
    {
        try {
            $dbConn    = $this->getDbConnection();
            $tableName = $this->sessionTableName;
            $db        = $dbConn->selectDatabase($this->connectionID);

            $db->{$tableName}->remove(["session_id" => $id]);
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /******************************************************************************
     * Session GC (garbage collection) handler.
     * This method should be overridden if {@link useCustomStorage} is set true.
     * Do not call this method directly.
     *
     * @param integer $maxLifetime the number of seconds after which data
     *                             will be seen as 'garbage' and cleaned up.
     *
     * @return boolean whether session is GCed successfully
     */
    public function gcSession($maxLifetime)
    {
        try {
            $dbConn    = $this->getDbConnection();
            $tableName = $this->sessionTableName;
            $db        = $dbConn->selectDatabase(constant($this->connectionID));

            $db->{$tableName}->remove(['$lt' => ["expire" => time()]]);
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /******************************************************************************
     * Updates the current session id with a newly generated one.
     * Please refer to {@link http://php.net/session_regenerate_id} for more details.
     *
     * @param boolean $deleteOldSession Whether to delete the old associated session file or not.
     *
     * @return bool|void
     */
    public function regenerateID($deleteOldSession = false)
    {
        $oldID = session_id();

        // if no session is started, there is nothing to regenerate
        if (empty($oldID)) {
            return true;
        }

        parent::regenerateID(false);

        $newID     = session_id();
        $dbConn    = $this->getDbConnection();
        $tableName = $this->sessionTableName;
        $db        = $dbConn->selectDatabase(constant($this->connectionID));

        if (!$deleteOldSession) {
            $update = $db->{$tableName}->findAndModify(
                ["session_id" => $oldID],
                [
                    '$set' => [
                        'session_id'  => $newID,
                        'last_active' => time(),
                        'UserID'      => $this->getUserId()
                    ]
                ],
                null,
                ["new" => true]
            );

            return true;
        }

        $sessionData = [
            'session_id'  => $newID,
            'UserID'      => $this->getUserId(),
            'expire'      => time() + $this->getTimeout(),
            'last_active' => time(),
            'data'        => '',
        ];
        $insertion   = $db->{$tableName}->insertOne($sessionData);

        return true;
    }
}


