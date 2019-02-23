<?php
require __DIR__ . '/vendor/autoload.php';

use Moometric\SemnaturaPlus;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\BrowserConsoleHandler;

# Create the logger
$logger = new Logger('signature');
# log to file
$logger->pushHandler(new StreamHandler(__DIR__.'/logs/signature.log', Logger::DEBUG));

if ( php_sapi_name() === 'cli' ) {
  # log to console
  $logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
} else {
  # log to browser console
  echo "<h5>Open Browser Console for more info on what's been done. Check the logs directory for full logging.</h5>";
  $logger->pushHandler(new BrowserConsoleHandler(Logger::INFO));
}

$plusSig = new SemnaturaPlus($logger);

# Setting test mode so no changes are written. Switch to false to actually perform changes.
$plusSig->addSettingRunTestMode(TRUE);
echo "<h4>Test Mode is set to <b>" . var_export($plusSig->setting_testing_mode, TRUE) . "</b></h4>";

# Preview Signature.
$plusSig->addSettingPreviewSignature(TRUE);
echo "<h4>Preview Signature is set to <b>" . var_export($plusSig->setting_preview_template, TRUE) . "</b></h4>";

$filtered_users = $plusSig->processUsersList($plusSig->getUsersList());
$need_sig_update = $plusSig->getUsersDiff($filtered_users);

if ( !empty($need_sig_update) ) {
  $filtered_users = $plusSig->reapplyOverridesToNewData($filtered_users, $need_sig_update);
  $sig_groups = $plusSig->buildSigGroups($need_sig_update, $filtered_users);

  foreach ( $sig_groups as $name => $sig_group ) {
    $plusSig->addSettingSetTemplate($name . ".html")
           ->addSettingFilterEmailsToUpdate($sig_group)
           ->updateSignatures($filtered_users);
  }
} else {
  echo "<h4>No signatures need updating!</h4>";
}
