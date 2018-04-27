<?php
namespace NoSQL;

/****************************************************************************
 * Yii RecordBrowser.php
 * Light weight script to used to browse and filter databased records
 * which have associated Yii Models.
 *
 * Author:
 *          - Twitter: @jamesxolincoln
 *          - Email:   james@giftastic.me
 *
 * =========================================================
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *****************************************************************************/
class CMongoRecordBrowser
{
//        private $modelAlias   = null;
    private $modelType     = null;
    private $cacheEnabled  = true;
    private $caching       = true;
    private $cacheLife     = 300;
    private $cacheKey      = array();
    private $conditions    = array();
    private $paging        = null;
    private $perPage       = null;
    private $sortBy        = null;
    private $sortDirection = null;
    private $criteria      = [];
    private $cacheHit      = false;
    private $pageNo        = 0;

    private $opMap = ['and' => '$and', 'or' => '$or'];

    /***********************************************
     * CMongoRecordBrowser constructor.
     *
     * @param $modelType
     *
     * @throws \Exception
     */
    public function __construct($modelType)
    {
        if (!class_exists($modelType)) {
            throw new \Exception("{$modelType} is not a callable class");
        }

        $this->modelType  = $modelType;
        $this->paging     = new \AppPagination();
        $this->conditions = [];
        $this->cacheKey   = [];
        $this->perPage    = 5;
    }

    /***********************************************
     * @param null $order
     */
    public function orderBy($order = null)
    {
        $this->cacheKey[] = 'ORDERBY-' . $order;
        $this->sortBy     = $order;
    }

    /***********************************************
     * @return bool
     */
    public function isCachingEnabled()
    {
        if (true == $this->cacheEnabled) {
            return true;
        }

        return false;
    }

    /***********************************************
     * @return bool
     */
    public function isCaching()
    {
        if (true == $this->caching) {
            return true;
        }

        return false;
    }

    /***********************************************
     * @return int
     */
    public function cacheLifeGet()
    {
        return $this->cacheLife;
    }

    /***********************************************
     * @param $seconds
     *
     * @return $this
     */
    public function cacheLifeSet($seconds)
    {
        $this->cacheLife = $seconds;

        return $this;
    }

    /***********************************************
     * @return null
     */
    public function modelTypeGet()
    {
        return $this->modelType;
    }

    /***********************************************
     * @return array
     */
    public function fields()
    {
        /**
         * @var CustActiveRecord $m
         */
        $m = $this->modelType;

        return $m::model()->attributeNames();
    }

    /***********************************************
     * @param int $direction
     *
     * @return $this|null
     */
    public function sortDirection($direction = 0)
    {
        if ($direction == 0) {
            return $this->sortDirection;
        }
        elseif ($direction == -1) {
            $this->sortDirection = -1;
        }
        else {
            $this->sortDirection = 1;
        }

        return $this;
    }

    /***********************************************
     * ct (Contains) Condition
     *
     * @param        $attribute
     * @param        $value
     * @param string $op
     *
     * @return $this
     */
    public function ctCondition($attribute, $value, $op = 'and')
    {
        $regex                     = new \MongoDB\BSON\Regex ($value, 'i');
        $this->conditions[ $op ][] = ["{$attribute}" => ['$regex' => $regex]];
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}-{$value}";

        return $this;
    }

    /***********************************************
     * bw (Begins With) Condition
     *
     * @param        $attribute
     * @param        $value
     * @param string $op
     *
     * @return $this
     */
    public function bwCondition($attribute, $value, $op = 'and')
    {
        $regex                     = new \MongoDB\BSON\Regex ('^' . $value, 'i');
        $this->conditions[ $op ][] = ["{$attribute}" => ['$regex' => $regex]];
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}-{$value}";

        return $this;
    }

    /***********************************************
     * ew (Ends With) Condition
     *
     * @param        $attribute
     * @param        $value
     * @param string $op
     *
     * @return $this
     */
    public function ewCondition($attribute, $value, $op = 'and')
    {
        $regex                     = new \MongoDB\BSON\Regex ($value . '$', 'i');
        $this->conditions[ $op ][] = ["{$attribute}" => ['$regex' => $regex]];
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}-{$value}";

        return $this;
    }

    /***********************************************
     * @param        $attribute
     * @param        $regex
     * @param string $op
     *
     * @return $this
     */
    public function regexCondition($attribute, $regex, $op = 'and')
    {
        $regex                     = new \MongoDB\BSON\Regex ($regex, 'i');
        $this->conditions[ $op ][] = ["{$attribute}" => ['$regex' => $regex]];
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}-{$regex}";

        return $this;
    }

    /***********************************************
     * eq (Equals) Condition
     *
     * @param        $attribute
     * @param        $value
     * @param string $op
     *
     * @return $this
     */
    public function eqCondition($attribute, $value, $op = 'and')
    {
        $this->conditions[ $op ][] = ["{$attribute}" => ['$eq' => $value]];
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}-{$value}";

        return $this;
    }

    /***********************************************
     * ne (Not Equals) Condition
     *
     * @param        $attribute
     * @param        $value
     * @param string $op
     *
     * @return $this
     */
    public function neCondition($attribute, $value, $op = 'and')
    {
        $this->conditions[ $op ][] = ["{$attribute}" => ['$ne' => $value]];
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}-{$value}";

        return $this;
    }

    /***********************************************
     * gt (Greater Than) Condition
     *
     * @param        $attribute
     * @param        $value
     * @param string $op
     *
     * @return $this
     */
    public function gtCondition($attribute, $value, $op = 'and')
    {
        $this->conditions[ $op ][] = ["{$attribute}" => ['$gt' => $value]];
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}-{$value}";

        return $this;
    }

    /***********************************************
     * gte (Greater Than or equal to) Condition
     *
     * @param        $attribute
     * @param        $value
     * @param string $op
     *
     * @return $this
     */
    public function gteCondition($attribute, $value, $op = 'and')
    {
        $this->conditions[ $op ][] = ["{$attribute}" => ['$gte' => $value]];
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}-{$value}";

        return $this;
    }

    /***********************************************
     * lt (Less Than) Condition
     *
     * @param        $attribute
     * @param        $value
     * @param string $op
     *
     * @return $this
     */
    public function ltCondition($attribute, $value, $op = 'and')
    {
        $this->conditions[ $op ][] = ["{$attribute}" => ['$lt' => $value]];
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}-{$value}";

        return $this;
    }

    /***********************************************
     * lte (Less Than) Condition
     *
     * @param        $attribute
     * @param        $value
     * @param string $op
     *
     * @return $this
     */
    public function lteCondition($attribute, $value, $op = 'and')
    {
        $this->conditions[ $op ][] = ["{$attribute}" => ['$lte' => $value]];
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}-{$value}";

        return $this;
    }

    /***********************************************
     * between (Between) Condition
     *
     * @param        $attribute
     * @param        $low_value
     * @param        $hi_value
     * @param string $op
     *
     * @return $this
     */
    public function betweenCondition($attribute, $low_value, $hi_value, $op = 'and')
    {
        $this->conditions[ $op ][] = ["{$attribute}" => ['$lt' => $hi_value, '$gt' => $hi_value]];
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}-{$low_value}-{$hi_value}";

        return $this;
    }

    /***********************************************
     * @param        $attribute
     * @param array  $list
     * @param string $op
     *
     * @return $this
     */
    public function inCondition($attribute, $list = [], $op = 'and')
    {
        $this->conditions[ $op ][] = ["{$attribute}" => ['$in' => (array)$list]];
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}" . implode("-", $list);

        return $this;
    }

    /***********************************************
     * @param        $attribute
     * @param array  $list
     * @param string $type
     * @param string $op
     *
     * @return $this
     */
    public function notInCondition($attribute, $list = [], $type = 'numeric', $op = 'and')
    {
        $this->conditions[ $op ][] = ["{$attribute}" => ['$nin' => (array)$list]];
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}" . implode("-", $list);

        return $this;
    }

    /***********************************************
     * @param        $attribute
     * @param string $op
     *
     * @return $this
     */
    public function isNullCondition($attribute, $op = 'and')
    {
        $this->conditions[ $op ][] = "{$attribute} IS NULL";
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}";

        return $this;
    }

    /***********************************************
     * @param        $attribute
     * @param string $op
     *
     * @return $this
     */
    public function arrayAllCondition($attribute, $list = [], $op = 'and')
    {
        $this->conditions[ $op ][] = ["{$attribute}" => ['$all' => (array) $list]];
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}" . implode("-", $list);

        return $this;
    }

    /***********************************************
     * @param        $attribute
     * @param string $op
     *
     * @return $this
     */
    public function arrayMatchCondition($attribute, $conditions = [], $op = 'and')
    {
        $this->conditions[ $op ][] = ["{$attribute}" => ['$elemMatch' => (array) $conditions]];
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}" . implode("-", $conditions);

        return $this;
    }

    /***********************************************
     * @param        $attribute
     * @param string $op
     *
     * @return $this
     */
    public function isNotNullCondition($attribute, $op = 'and')
    {
        $this->conditions[ $op ][] = "{$attribute} IS NOT NULL";
        $this->cacheKey[]          = __FUNCTION__ . "-{$attribute}";

        return $this;
    }

    /***********************************************
     * Build condition criteria
     * @return $this
     */
    public final function generateCriteria()
    {
        $c = $this->conditions;
        $f = [];
        if (is_array($c)) {
            foreach ($c as $op => $conditions) {
                foreach ($conditions as $condition) {
                    $this->criteria[ $this->opMap[ strtolower($op) ] ][] = $condition;
                }
            }
        }

        return $this;
    }

    /***********************************************
     * @return array
     */
    public final function conditionsGet()
    {
        return $this->conditions;
    }

    /***********************************************
     * RETURN THE NUMBER OF RECORDS GENERATED BY QUERY
     * @return string
     */
    public final function recordCount()
    {
        /**
         * @var CustActiveRecord $m
         */
        $m = $this->modelType;
        $this->generateCriteria();

        return $count = $m::model()->count($this->criteria, []);
    }

    /***********************************************
     * @param $pageNo
     *
     * @return $this
     */
    public function pageNoSet($pageNo)
    {
        $this->pageNo = (int)$pageNo;

        return $this;
    }

    /***********************************************
     * @return int
     */
    public function pageNo()
    {
        return $this->pageNo;
    }

    /***********************************************
     * @param int $perPage
     *
     * @return $this
     */
    public final function perPageSet($perPage = 1)
    {
        $this->perPage = (int)$perPage;

        return $this;
    }

    /***********************************************
     * RETURN THE AppPagination MODULE, A CUSTOM INSTANCE OF CPagination
     * @return \AppPagination|null
     */
    public final function paging()
    {
        return $this->paging;
    }

    /***********************************************
     *
     */
    public final function pageCount()
    {
        $this->recordCount();

        return $this->paging()->getPageCount();
    }

    /***********************************************
     * @return $this
     */
    public function cachePages()
    {
        $this->recordCount();
        $pageCount = $this->paging()->getPageCount();

        $x = 0;

        while ($x < $pageCount) {
            $x++;
            $this->browse($x);
        }

        return $this;
    }

    /***********************************************
     * @param null $pageNo
     *
     * @return array
     */
    public function pagingInfo($pageNo = null)
    {
        /**
         * @var CMongoModel $m
         */
        $m      = $this->modelType;
        $pageNo = (int)(is_null($pageNo) ? $this->pageNo : $pageNo);
        $this->paging->setCurrentPage(((int)$pageNo) - 1);

        $this->generateCriteria();

        $count = (int)$m::model()->count($this->criteria);
        $this->paging->setItemCount($count);
        $this->paging->pageSize = (int)$this->perPage;

        return $this->paging->details();
    }

    /***********************************************
     * @return string
     */
    public final function getCacheKey()
    {
        $cacheKeyItems = $this->cacheKey;

        return md5($this->modelType . "::" . (implode("::", $cacheKeyItems)));
    }

    /***********************************************
     * @param int  $pageNo
     * @param bool $forceRefresh
     *
     * @return array
     */
    public final function browse($pageNo = 0, $forceRefresh = false)
    {
        /**
         * @var CustActiveRecord $m
         */
        $m = $this->modelType;

        $pageNo           = (int)(is_null($pageNo) ? $this->pageNo : $pageNo);
        $this->cacheKey[] = 'page-' . $pageNo;
        $this->cacheKey[] = 'perPage-' . $this->perPage;

        $limit       = $this->perPage;
        $limitOffset = $pageNo > 0 ? (int)(($pageNo * $limit) - $limit) : 0;

        $this->cacheKey[] = 'LIMIT-' . (int)$limit;
        $this->cacheKey[] = 'OFFSET-' . (int)$limitOffset;

        $this->generateCriteria();
        $this->paging->setItemCount($this->recordCount());
        $this->paging->setCurrentPage(((int)$pageNo) - 1);
        $this->paging->pageSize = (int)$this->perPage;

        $records = [];
        if (false == $forceRefresh
            && true == $this->cacheEnabled
            && ($records = \Yii::app()->cache->get($this->getCacheKey()))
        ) {
            $this->cacheHit = true;
        }

        if (!isset($records) || empty($records)) {

            $options = [
                'skip'  => $limitOffset,
                'limit' => $limit,
            ];

            if(!empty($this->sortBy)){
                $options['sort']  = [$this->sortBy => (int) $this->sortDirection()];
            }

            $records = $m::model()->findAll($this->criteria, $options);

            if ($this->cacheEnabled && !empty($records)) {
                \Yii::app()->cache->add($this->getCacheKey(), $records, $this->cacheLife);
            }
            else {
                $records = [];
            }
        }

        return $records;
    }

    /***********************************************
     * @return bool
     */
    public function isCacheHit()
    {
        return $this->cacheHit;
    }
}