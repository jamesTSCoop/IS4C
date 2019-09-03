<?php
/**
  Script to email end of fiscal year statements
  to all loan account holders
*/
if (basename(__FILE__) != basename($_SERVER['PHP_SELF'])) {
    return;
}
if (php_sapi_name() != 'cli') {
    return;
}

include(dirname(__FILE__) . '/../../../../config.php');
if (!class_exists('FannieAPI')) {
    include(__DIR__ . '/../../../../classlib2.0/FannieAPI.php');
}
$dbc = FannieDB::get($FANNIE_PLUGIN_SETTINGS['GiveUsMoneyDB']);

$endFY = mktime(0, 0, 0, GumLib::getSetting('FYendMonth'), GumLib::getSetting('FYendDay'), date('Y'));
echo "Sending statements for " . date('Y-m-d', $endFY) . "\n";

$url = 'http://localhost' . $FANNIE_URL . 'modules/plugins2.0/GiveUsMoneyPlugin/GumEmailPage.php';

$paidP = $dbc->prepare('SELECT checkIssued
    FROM GumLoanPayoffMap AS m
        INNER JOIN GumPayoffs AS p ON m.gumPayoffID=p.gumPayoffID
    WHERE m.gumLoanAccountID=?');

$loans = new GumLoanAccountsModel($dbc);
// GumLoanAccounts.loanDate < end of fiscal year
$loans->loanDate(date('Y-m-d 00:00:00', $endFY), '<');
//$loans->card_no(11270);
foreach ($loans->find('loanDate') as $loan) {
    if ($loan->card_no() == 652 || $loan->card_no() == 6780) continue;

    $paid = $dbc->getValue($paidP, array($loan->gumLoanAccountID()));
    if ($paid) {
        echo "SKIP " . $loan->gumLoanAccountID() . "\n";
        continue;
    }
    echo 'Sending account# ' .$loan->accountNumber() . ' ' .$loan->loanDate() . "\n";
    $qs = '?id=' . $loan->accountNumber() . '&loanstatement=1';
    $ch = curl_init($url . $qs);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
}
