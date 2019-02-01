<?php
require __DIR__ . '/vendor/autoload.php';

use Moometric\mooSignature;

// Update with your GSuite domain and admin email address
$admin_email = "admin@domain.com";
$domain = "domain.com";

// If the credentials or signatures are set in other paths than the default ones, set them below
// $mooSig->addSettingServiceAccountPath("/your/project/path/local_vars/");
// $mooSig->addsettingSignaturePath("/your/project/path/signatures/");

$mooSig = new mooSignature($domain, $admin_email);

// Setting test mode so no changes are written. Switch to false to actually perform changes.
$mooSig->addSettingRunTestMode(true);
// Preview Signature.
$mooSig->addSettingPreviewSignature(true);
// Setting the default signature
$mooSig->addSettingSetTemplate("defaultSig.html");

// Update signatures based on a whitelist, so only for those added below
// $mooSig->addSettingFilterEmailsToUpdate(["user@domain.com", "user2@domain.com"]);

// Update the signature for all users but exclude those who don't have a profile photo or title set.
// $mooSig->addSettingSkipConditions(["title", "thumbnailPhotoUrl"]);

// Update the signature for all users
$mooSig->updateSignatures();

echo "<h2>List of available merge fields which can be used in the signature</h2>";
$mooSig->listMergeFields();
