<?php

namespace mycademy\nestedrest;

class NestedRelationUrlRule extends \yii\rest\UrlRule
{
    public $relation = null;

    public $link_attribute = null;

}
