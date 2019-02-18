<?php
require __DIR__ . '/vendor/autoload.php';

use Moometric\SemnaturaPlus;

$plusSig = new SemnaturaPlus();

// Setting test mode so no changes are written. Switch to false to actually perform changes.
$plusSig->addSettingRunTestMode(TRUE);
echo "<h4>Test Mode is set to " . $plusSig->setting_testing_mode . "</h4>";

// Preview Signature.
$plusSig->addSettingPreviewSignature(TRUE);
echo "<h4>Preview Signature is set to " . $plusSig->setting_preview_template . "</h4>";

$filtered_users = $plusSig->processUsersList($plusSig->getUsersList());
$need_sig_update = $plusSig->getUsersDiff($filtered_users);
$filtered_users = $plusSig->reapplyOverridesToNewData($filtered_users, $need_sig_update);
echo "<h4>The following aliases need updating, with the pasted data.</h4>";
echo "<h5>Not all may actually be updated, due to filtering</h5>";
foreach ( $need_sig_update as $alias ) {
  echo "<hr>";
  var_dump($alias);
  var_dump($filtered_users[$alias]);
  echo "<hr>";
}

if ( !empty($need_sig_update) ) {
  $sig_groups = $plusSig->buildSigGroups($need_sig_update, $filtered_users);

  foreach ( $sig_groups as $name => $sig_group ) {
    $plusSig->addSettingSetTemplate($name . ".html")
           ->addSettingFilterEmailsToUpdate($sig_group)
           ->updateSignatures($filtered_users);
  }
}
