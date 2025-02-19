<?php
/**
 * @link https://github.com/tunecino/yii2-nested-rest
 * @copyright Copyright (c) 2016 Salem Ouerdani
 * @license http://opensource.org/licenses/BSD-3-Clause
 */

namespace mycademy\nestedrest;

use Yii;
use yii\base\InvalidConfigException;
use yii\data\ActiveDataProvider;
use yii\data\DataFilter;
use yii\data\Pagination;
use yii\data\Sort;
use yii\helpers\ArrayHelper;
use yii\web\NotFoundHttpException;
use yii\helpers\StringHelper;

/**
 * Action is the base class for nested action classes that depends on the custom UrlRule class.
 *
 * @author Salem Ouerdani <tunecino@gmail.com>
 */
class Action extends \yii\rest\Action
{

    /**
     * @var callable|null a PHP callable that will be called to prepare a data provider that
     * should return a collection of the models. If not set, [[prepareDataProvider()]] will be used instead.
     * The signature of the callable should be:
     *
     * ```php
     * function (IndexAction $action) {
     *     // $action is the action object currently running
     * }
     * ```
     *
     * The callable should return an instance of [[ActiveDataProvider]].
     *
     * If [[dataFilter]] is set the result of [[DataFilter::build()]] will be passed to the callable as a second parameter.
     * In this case the signature of the callable should be the following:
     *
     * ```php
     * function (IndexAction $action, mixed $filter) {
     *     // $action is the action object currently running
     *     // $filter the built filter condition
     * }
     * ```
     */
    public $prepareDataProvider;
    /**
     * @var callable a PHP callable that will be called to prepare query in prepareDataProvider.
     * Should return $query.
     * For example:
     *
     * ```php
     * function ($query, $requestParams) {
     *     $query->andFilterWhere(['id' => 1]);
     *     ...
     *     return $query;
     * }
     * ```
     *
     * @since 2.0.42
     */
    public $prepareSearchQuery;
    /**
     * @var DataFilter|null data filter to be used for the search filter composition.
     * You must set up this field explicitly in order to enable filter processing.
     * For example:
     *
     * ```php
     * [
     *     'class' => 'yii\data\ActiveDataFilter',
     *     'searchModel' => function () {
     *         return (new \yii\base\DynamicModel(['id' => null, 'name' => null, 'price' => null]))
     *             ->addRule('id', 'integer')
     *             ->addRule('name', 'trim')
     *             ->addRule('name', 'string')
     *             ->addRule('price', 'number');
     *     },
     * ]
     * ```
     *
     * @see DataFilter
     *
     * @since 2.0.13
     */
    public $dataFilter;
    /**
     * @var array|Pagination|false The pagination to be used by [[prepareDataProvider()]].
     * If this is `false`, it means pagination is disabled.
     * Note: if a Pagination object is passed, it's `params` will be set to the request parameters.
     * @see Pagination
     * @since 2.0.45
     */
    public $pagination = [];
    /**
     * @var array|Sort|false The sorting to be used by [[prepareDataProvider()]].
     * If this is `false`, it means sorting is disabled.
     * Note: if a Sort object is passed, it's `params` will be set to the request parameters.
     * @see Sort
     * @since 2.0.45
     */
    public $sort = [];

    /**
     * @var string class name of the related model.
     * This should be provided by the UrlClass within queryParams.
     */
    protected $relativeClass;
    /**
     * @var string name of the resource. used to generating the related 'prefix'.
     * This should be provided by the UrlClass within queryParams.
     */
    protected $relationName;
    /**
     * @var string name of the attribute name used as a foreign key in the related model. also used to build the 'prefix'.
     * This should be provided by the UrlClass within queryParams.
     */
    protected $linkAttribute;
    /**
     * @var primary key value of the linkAttribute.
     * This should be provided by the UrlClass within queryParams.
     * @see linkAttribute
     */
    protected $relative_id;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $params = Yii::$app->request->queryParams;

        if ($this->expectedParams($params) === false) {
            throw new InvalidConfigException("unexpected configurations.");
        }

        $this->relativeClass = $params['relativeClass'];
        $this->relationName  = $params['relationName'];
        $this->linkAttribute = $params['linkAttribute'];
        $this->relative_id   = $params[$this->linkAttribute];
    }

    /**
     * Checks if the expected params that should be provided by the custom UrlClass are not missing.
     * @return Bolean.
     */
    protected function expectedParams($params)
    {
        $expected = ['relativeClass', 'relationName', 'linkAttribute'];
        foreach ($expected as $attr) {
            if (isset($params[$attr]) === false || ($attr === 'linkAttribute' && isset($params[$params[$attr]]) === false)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Finds the related model.
     * @return \yii\db\ActiveRecordInterface.
     * @throws NotFoundHttpException if not found.
     */
    public function getRelativeModel()
    {
        $relativeClass = $this->relativeClass;
        $relModel = $relativeClass::findOne($this->relative_id);

        if ($relModel === null) {
            throw new NotFoundHttpException(StringHelper::basename($relativeClass) . " '$this->relative_id' not found.");
        }

        if ($this->checkAccess) {
            call_user_func($this->checkAccess, $this->id, $relModel);
        }

        return $relModel;
    }

    /**
     * Finds the model or the list of models corresponding
     * to the specified primary keys values within the relative model retreived by [[getRelativeModel()]].
     * @param string $IDs should hold the list of IDs related to the models to be loaded.
     * it must be a string of the primary keys values separated by commas.
     * @return \yii\db\ActiveRecordInterface
     * @throws NotFoundHttpException if not found or not related.
     */
    public function findCurrentModels($IDs)
    {
        $modelClass = $this->modelClass;
        $pk = $modelClass::primaryKey()[0];
        $ids = preg_split('/\s*,\s*/', $IDs, -1, PREG_SPLIT_NO_EMPTY);
        $getter = 'get' . $this->relationName;

        $relModel = $this->getRelativeModel();
        $q = $relModel->$getter()->andWhere([$pk => $ids]);

        $ci = count($ids);
        $model = $ci > 1 ? $q->all() : $q->one();

        if ($model === null || (is_array($model) && count($model) !== $ci)) {
            throw new NotFoundHttpException("Not found or unrelated objects.");
        }

        return $model;
    }

    /**
     * Prepares the data provider that should return the requested collection of the models.
     * @return ActiveDataProvider
     */
    protected function prepareDataProvider()
    {
        $requestParams = Yii::$app->getRequest()->getBodyParams();
        if (empty($requestParams)) {
            $requestParams = Yii::$app->getRequest()->getQueryParams();
        }

        $filter = null;
        if ($this->dataFilter !== null) {
            $this->dataFilter = Yii::createObject($this->dataFilter);
            if ($this->dataFilter->load($requestParams)) {
                $filter = $this->dataFilter->build();
                if ($filter === false) {
                    return $this->dataFilter;
                }
            }
        }

        if ($this->prepareDataProvider !== null) {
            return call_user_func($this->prepareDataProvider, $this, $filter);
        }

        /* @var $modelClass \yii\db\BaseActiveRecord */
        $modelClass = $this->modelClass;

        $query = $modelClass::find();
        if (!empty($filter)) {
            $query->andWhere($filter);
        }
        if (is_callable($this->prepareSearchQuery)) {
            $query = call_user_func($this->prepareSearchQuery, $query, $requestParams);
        }

        if (is_array($this->pagination)) {
            $pagination = ArrayHelper::merge(
                [
                    'params' => $requestParams,
                ],
                $this->pagination
            );
        } else {
            $pagination = $this->pagination;
            if ($this->pagination instanceof Pagination) {
                $pagination->params = $requestParams;
            }
        }

        if (is_array($this->sort)) {
            $sort = ArrayHelper::merge(
                [
                    'params' => $requestParams,
                ],
                $this->sort
            );
        } else {
            $sort = $this->sort;
            if ($this->sort instanceof Sort) {
                $sort->params = $requestParams;
            }
        }

        return Yii::createObject([
            'class' => ActiveDataProvider::className(),
            'query' => $query,
            'pagination' => $pagination,
            'sort' => $sort,
        ]);
    }
}
