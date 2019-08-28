<?php

/**
 * Copyright Shopgate Inc.
 *
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
 *
 * @author    Shopgate Inc, 804 Congress Ave, Austin, Texas 78701 <interfaces@shopgate.com>
 * @copyright Shopgate Inc
 * @license   http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

namespace Shopgate\Bolt\Model\ResourceModel\Shopgate;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ShopgateQuoteFlag extends AbstractDb
{
    /**
     * Define main table
     */
    protected function _construct()
    {
        $this->_init('shopgate_bolt_quote_flags', 'id');
    }

    /**
     * Gets flag by quote_id
     *
     * @param integer $quoteId
     *
     * @return array
     * @throws LocalizedException
     */
    public function getByQuoteId($quoteId)
    {
        $connection = $this->getConnection();
        $select     = $connection->select()->from($this->getMainTable())->where('quote_id = :quoteId');
        $bind       = [':quoteId' => (integer) $quoteId];

        return $connection->fetchRow($select, $bind);
    }
}
