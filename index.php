<?php
require __DIR__ . '/vendor/autoload.php';

use Moometric\SemnaturaPlus;

$plusSig = new SemnaturaPlus();

// Setting test mode so no changes are written. Switch to false to actually perform changes.
$plusSig->addSettingRunTestMode(TRUE);
echo "<h4>Test Mode is set to <b>" . var_export($plusSig->setting_testing_mode, TRUE) . "</b></h4>";

// Preview Signature.
$plusSig->addSettingPreviewSignature(TRUE);
echo "<h4>Preview Signature is set to <b>" . var_export($plusSig->setting_preview_template, TRUE) . "</b></h4>";

$filtered_users = $plusSig->processUsersList($plusSig->getUsersList());
$need_sig_update = $plusSig->getUsersDiff($filtered_users);
$filtered_users = $plusSig->reapplyOverridesToNewData($filtered_users, $need_sig_update);

if ( !empty($need_sig_update) ) {
  $sig_groups = $plusSig->buildSigGroups($need_sig_update, $filtered_users);

  foreach ( $sig_groups as $name => $sig_group ) {
    $plusSig->addSettingSetTemplate($name . ".html")
           ->addSettingFilterEmailsToUpdate($sig_group)
           ->updateSignatures($filtered_users);
  }
} else {
  echo "<h3>No signatures need updating!</h3>";
}
