<?php

use COREPOS\Fannie\API\lib\Operators as Op;

include(__DIR__ . '/../../../config.php');
if (!class_exists('FannieAPI')) {
    include(__DIR__ . '/../../../classlib2.0/FannieAPI.php');
}

class RpDailyPage extends FannieRESTfulPage
{
    protected $header = 'RP Daily Page';
    protected $title = 'RP Daily Page';

    protected function get_view()
    {
        $store = FormLib::get('store', COREPOS\Fannie\API\lib\Store::getIdByIp());

        $ts = time();
        while (date('N', $ts) != 1) {
            $ts = mktime(0, 0, 0, date('n', $ts), date('j', $ts) - 1, date('Y', $ts));
        }

        $sales = $this->salesTable($store, $ts);
        $greens = $this->greensTable($store);
        $preps = $this->prepsTable($store);
        $stock = $this->stockFirst($store);
        $today = date('l, F jS');

        $stores = FormLib::storePicker('store', false, "window.location='RpDailyPage.php?store='+this.value");

        return <<<HTML
<div class="row">
    <div class="col-sm-5">
        <h3>{$today}</h3>
    </div>
    <div class="col-sm-5">
        {$stores['html']}
    </div>
</div>
<div class="row">
    <div class="col-sm-8">
        <div class="row=">
            {$sales}
        </div>
        <div class="row=">
            <table class="table table-bordered">
                <tr><th style="text-align: center;" align="center">On Shift Today / Samples</th></tr>
                <tr><td>&nbsp;</td></tr>
                <tr><td>&nbsp;</td></tr>
                <tr><td>&nbsp;</td></tr>
                <tr><td>&nbsp;</td></tr>
                <tr><td>&nbsp;</td></tr>
                <tr><td>&nbsp;</td></tr>
            </table>
        </div>
        <div class="row=">
            <div class="col-sm-6">
                {$preps}
            </div>
            <div class="col-sm-6">
                {$greens}
            </div>
        </div>
    </div>
    <div class="col-sm-3">
        {$stock}
    </div>
</div>
HTML;
    }

    private function salesTable($store, $ts)
    {
        $segP = $this->connection->prepare("SELECT * FROM RpSegments WHERE storeID=? AND startDate=?");
        $seg = $this->connection->getRow($segP, array($store, date('Y-m-d', $ts)));
        $ret = '<table class="table table-bordered table-striped">
            <tr><th>Day</th><th>Goal</th><th>Actual</th><th>Growth</th></tr>';
        if ($seg === false) {
            return $ret . '<tr><td colspan="4">No Data</td></tr></table>';
        }

        $ttl = $seg['sales'];
        $pcts = json_decode($seg['segmentation'], true);
        $thisYear = json_decode($seg['thisYear'], true);
        $modify = array('plan'=>0, 'this'=>0, 'points'=>0);
        foreach ($pcts as $day => $pct) {
            $plan = $ttl * $pct;
            $cur = $thisYear[$day] > 0 ? $thisYear[$day] : '';
            $ret .= sprintf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%.2f%%</td></tr>',
                $day,
                number_format($plan, 2),
                ($cur ? number_format($cur, 2) : ''),
                ($cur ? (($cur - $plan) / $cur) * 100 : 0)
            );
            if ($cur) {
                $modify['plan'] += $plan;
                $modify['this'] += $cur;
                $modify['points']++;
            }
        }
        if ($modify['points']) {
            $mod = ($modify['this'] - $modify['plan']) / $modify['plan'];
            $mod *= $modify['points'] / 7;
            $ttl = $ttl * (1 + $mod);
        }
        $ret .= '<tr><th colspan="2">Projected Total</th><th>'
            . number_format($ttl, 2) . '</th></tr></table>';

        return $ret;
    } 

    private function greensTable($store)
    {
        $res = $this->connection->query("
            SELECT *
            FROM RpGreens AS g
                INNER JOIN likeCodes AS l ON l.likeCode=g.likeCode");
        $retailP = $this->connection->prepare("SELECT
            AVG(CASE WHEN discounttype=1 THEN special_price ELSE normal_price END)
            FROM upcLike AS u
                INNER JOIN products AS p ON u.upc=p.upc
            WHERE u.likeCode=?
                AND p.store_id=?");
        $infoP = $this->connection->prepare("SELECT *
            FROM RpOrderItems AS i
                LEFT JOIN " . FannieDB::fqn('Smoothed', 'plugin:WarehouseDatabase') . " AS w
                    ON i.upc=w.upc AND i.storeID=w.storeID
            WHERE i.upc=?
                AND i.storeID=?");
        $data = array();
        while ($row = $this->connection->fetchRow($res)) {
            $record = array('name' => $row['likeCodeDesc']);
            $record['retail'] = $this->connection->getValue($retailP, array($row['likeCode'], $store));
            $info = $this->connection->getRow($infoP, array('LC' . $row['likeCode'], $store));
            $record['smoothed'] = $info['movement'];
            $record['caseSize'] = $info['caseSize'];
            $record['total'] = $record['retail'] * $info['movement'];
            $cases = sprintf('%.1f', Op::div($info['movement'], $info['caseSize']));
            $last = substr($cases, -1);
            if ($last > 5) {
                $cases = ceil($cases);
            } elseif ($last > 0) {
                $cases = floor($cases) + 0.5;
            }
            if ($cases == 0) {
                $cases = 0.5;
            }
            $record['cases'] = $cases;
            $data[$row['likeCode']] = $record;
        }

        uasort($data, function($a, $b) {
            if ($a['total'] < $b['total']) {
                return 1;
            } elseif ($a['total'] > $b['total']) {
                return -1;
            }

            return 0;
        });

        $ret = '<table class="table table-bordered table-striped">
            <tr><th><a href="RpGreensPreps.php">Greens</a></th><th>Cases</th><th>Initial</th></tr>';
        foreach ($data as $row) {
            $ret .= sprintf('<tr><td>%s</td><td>%.1f</td><td></td><tr>',
                $row['name'], $row['cases']);
        }

        return $ret . '</table>';
    }

    private function prepsTable($store)
    {
        $res = $this->connection->query("
            SELECT *
            FROM RpPreps AS g
                INNER JOIN likeCodes AS l ON l.likeCode=g.likeCode");
        $retailP = $this->connection->prepare("SELECT
            AVG(CASE WHEN discounttype=1 THEN special_price ELSE normal_price END)
            FROM upcLike AS u
                INNER JOIN products AS p ON u.upc=p.upc
            WHERE u.likeCode=?
                AND p.store_id=?");
        $infoP = $this->connection->prepare("SELECT *
            FROM RpOrderItems AS i
                LEFT JOIN " . FannieDB::fqn('Smoothed', 'plugin:WarehouseDatabase') . " AS w
                    ON i.upc=w.upc AND i.storeID=w.storeID
            WHERE i.upc=?
                AND i.storeID=?");
        $data = array();
        while ($row = $this->connection->fetchRow($res)) {
            $record = array('name' => $row['likeCodeDesc']);
            $record['retail'] = $this->connection->getValue($retailP, array($row['likeCode'], $store));
            $info = $this->connection->getRow($infoP, array('LC' . $row['likeCode'], $store));
            $record['smoothed'] = $info['movement'];
            $record['caseSize'] = $info['caseSize'];
            $record['total'] = $record['retail'] * $info['movement'];
            $cases = sprintf('%.1f', Op::div($info['movement'], $info['caseSize']));
            $last = substr($cases, -1);
            if ($last > 5) {
                $cases = ceil($cases);
            } elseif ($last > 0) {
                $cases = floor($cases) + 0.5;
            }
            if ($cases == 0) {
                $cases = 0.5;
            }
            $record['cases'] = $cases;
            $data[$row['likeCode']] = $record;
        }

        uasort($data, function($a, $b) {
            if ($a['total'] < $b['total']) {
                return 1;
            } elseif ($a['total'] > $b['total']) {
                return -1;
            }

            return 0;
        });

        $ret = '<table class="table table-bordered table-striped">
            <tr><th><a href="RpGreensPreps.php">Repack</a></th><th>Cases</th><th>Initial</th></tr>';
        foreach ($data as $row) {
            $ret .= sprintf('<tr><td>%s</td><td>%.1f</td><td></td><tr>',
                $row['name'], $row['cases']);
        }

        return $ret . '</table>';
    }

    protected function stockFirst($store)
    {
        $nameP = $this->connection->prepare("SELECT likeCodeDesc FROM likeCodes WHERE likeCode=?");
        $dataP = $this->connection->prepare("SELECT r.upc
            FROM RpSubTypes AS r
                LEFT JOIN " . FannieDB::fqn('Smoothed', 'plugin:WarehouseDatabase') . " AS w ON r.upc=w.upc
            WHERE w.storeID=?
                AND subType='stock'
            ORDER BY w.movement * r.price DESC");
        $ret = '<table class="table table-bordered table-striped">
                <tr><th>Stock First</th></tr>';
        $dataR = $this->connection->execute($dataP, array($store));
        $count = 0;
        while ($row = $this->connection->fetchRow($dataR)) {
            $name = $this->connection->getValue($nameP, array(substr($row['upc'], 2)));
            $ret .= '<tr><td>' . $name . '</td></tr>';
            $count++;
            if ($count >= 30) {
                break;
            }
        }

        return $ret . '</table>';
    }
}

FannieDispatch::conditionalExec();

