<?php
/*******************************************************************************

    Copyright 2013 Whole Foods Co-op

    This file is part of CORE-POS.

    IT CORE is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    IT CORE is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

if (!class_exists('FannieDB')) {
    include(dirname(__FILE__).'/../../FannieDB.php');
}
if (!class_exists('BarcodeLib')) {
    include(dirname(__FILE__).'/../../../lib/BarcodeLib.php');
}

class BatchUpdateModel extends BasicModel
{
    protected $name = 'batchUpdate';

    protected $preferred_db = 'op';

    protected $columns = array(
    'batchUpdateID' => array('type'=>BIGINT(20), 'primary_key'=>true, 'increment'=>true),
    'updateType' => array('type'=>'VARCHAR(20)'),
    'upc' => array('type'=>'VARCHAR(13)'),
    'specialPrice' => array('type'=>'decimal(10,2)'),
    'batchID' => array('type'=>'BIGINT(2)'),
    'batchType' => array('SMALLINT(6)'),
    'modified' => array('DATETIME'),
    'user' => array('VARCHAR(20)')
    );

    const UPDATE_CREATE = 'BATCH CREATED';
    const UPDATE_DELETE = 'BATCH DELETED';
    const UPDATE_FORCED = 'BATCH FORCED';
    const UPDATE_STOPPED = 'BATCH STOPPED';
    const UPDATE_DATE_EDIT = 'BATCH DATES CHANGED';
    const UPDATE_PRICE_EDIT = 'ITEM PRICE CHANGED';
    const UPDATE_REMOVED = 'ITEM REMOVED FROM BATCH';
    const UPDATE_ADDED = 'ITEM ADDED TO BATCH';

    /*
        Requires tables batches & batchUpdate exist.
    */

    public function logUpdate($type='UNKNOWN', $user=false)
    {
        if (!$user) {
            $user = FannieAuth::getUID(FannieAuth::checkLogin());
        }
        
        /*
            if a UPC was passed to obj, use produpdate and batches modesl, 
                if BATCHID was passed, use only batches.
        */
        if ($this->upc) {
            $batchList = new BatchListModel($this->connection);
            $batchList->upc($this->upc);
            $batchList->batchID($this->batchID);
            $exists = $batchList->load();
            $logType = 'BATCH';
        } else {
            $batch = new BatchesModel($this->connection);
            $batch->batchID($this->batchID);
            $exists = $batch->load();
            $logType = 'ITEM';
        }
        if (!$exists) {
            return false;
        }

        /* 
            2 diff. kind of entries (item,batch)
        */   
        if ($logType === 'BATCH') {
            $this->updateType($type);
            $this->user->($user);
            $this->startDate($batch->startDate);
            $this->endDate($batch->endDate);
            $this->batchName($batch->batchName);
            $this->owner($batch->owner);
            $this->modified(date('Y-m-d H:i:s'));
            $saved = $this->save();
        } else {
            $this->updateType($type);
            $this->upc($this->upc);
            $this->specialPrice($batchList->salePrice);
            $this->batchID($batchList->batchID);
            $this->modified(date('Y-m-d H:i:s'));
            $saved = $this->save();
        }        
        if ($saved === false) {
            $json['error'] = 1;
            $json['msg'] = 'Error saving batch ' . $this->batchName;
        }
        
        /*
            a few things that are missing
                1. price changes batches don't need to be logged. 
                2. this method doesn't handle like code items being entered?? 
                   There is a chunck in prodUpdate that handles likecodes that 
                   may need to be added here. 
        */

        return true;
    }

    private $col_map = array(
        'upc' => 'p.upc',
        'description' => 'description',
        'price' => 'normal_price',
        'salePrice' => 'special_price',
        'cost' => 'cost',
        'dept' => 'department',
        'tax' => 'tax',
        'fs' => 'foodstamp',
        'wic' => 'wicable',
        'scale' => 'scale',
        'modified' => 'modified',
        'forceQty' => 'qttyEnforced',
        'noDisc' => 'discount',
        'inUse' => 'inUse',
        'likeCode' => 'likeCode',
        'storeID' => 'store_id',
    );

}

