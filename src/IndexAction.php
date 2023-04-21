<?php
/**
 * @link https://github.com/tunecino/yii2-nested-rest
 * @copyright Copyright (c) 2016 Salem Ouerdani
 * @license http://opensource.org/licenses/BSD-3-Clause
 */

namespace mycademy\nestedrest;

use Yii;
use yii\data\ActiveDataProvider;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @author Salem Ouerdani <tunecino@gmail.com>
 */
class IndexAction extends Action
{
    /**
     * Prepares the data provider that should return the requested
     * collection of the models within its related model.
     * @return ActiveDataProvider
     */
    public function run()
    {
        if ($this->checkAccess) {
            call_user_func($this->checkAccess, $this->id);
        }

        return new ActiveDataProvider([
            'query' => $this->buildQuery(),
        ]);
    }

    /**
     * @return ActiveQuery
     */
    protected function buildQuery()
    {
        $relModel = $this->getRelativeModel();
        $getter = 'get' . ucfirst($this->relationName);

        /** @var ActiveQuery $query */
        $query = $relModel->$getter();

        if ($query->via) {
            // Clear 'via' relation data and build inner join condition for performance
            // (otherwise yii will filter the results by selecting all junction tabel models).
            /* @var string $viaName */
            /* @var ActiveQuery $viaQuery */
            /* @var bool $viaCallableUsed */
            /* @var ActiveRecord $primaryModel */
            [$viaName, $viaQuery, $viaCallableUsed] = $query->via;
            $primaryModel = $query->primaryModel;

            $query->primaryModel = null;
            $query->via = null;

            $query->innerJoinWith([$viaName => function ($junctionQuery) use ($viaQuery) {
                $condition = [];
                foreach ($viaQuery->link as $column => $primaryColumn) {
                    $condition[$column] = $viaQuery->primaryModel->getAttribute($primaryColumn);
                }
                /** @var ActiveQuery $junctionQuery */
                $junctionQuery->andWhere($condition);
            }], false);
        }

        return $query;
    }
}
