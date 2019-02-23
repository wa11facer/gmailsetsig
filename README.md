## Initial Documentation 
- https://github.com/moometric/GSuiteSignatureManager/
- no longer fully relevant, due to changes

## Requirements
- PHP 7.1
- You are a GSuite domain admin with full super admin access
- PHP has access to read and modify the files in the folders `local_vars`, `logs` and `signatures`

## Description
- reads the GSuite users, filters out unneeded info and forms an assoc array with all the aliases found
- for the purposes of setting the signature, if a user has multiple sendAs aliases, each one is considered a separate entity so it can receive a different signature
- if there's no difference in the user data since the last run, no signature updates are performed. This includes changes to the manual overrides.
- the type of signature and the info within it are decided on a user by user basis

## Configuration

Is done through several `json` files, all found in the folder `local_vars`:

* `service-account.json` - GSuite credentials
* `user-data.json` - the latest data received from GSuite regarding the users. NOT to be edited manually, except if you want to force a full update, in which case you can just empty the file first.
* `overrides.json` - used for overwriting data received from GSuite. The format to use is as below, with the key being the **alias email**, not the primary one. 
 
```{
       "example@domain.com": {
           "phone0": 2345237985,
           "websites": "social_media_handle"
       },
      "example2@domain.com": {
          "fullname": "GG",
          "alreadyUpdated": [
              "fullname"
          ]
      }
   }
```

The key `alreadyUpdated` is set dynamically and should not be touched. It marks the overrides that have already been sent to GSuite, in order to not resend them each time (unless an update is made to that alias)

* `filters.json`
```{
    "testMode": true,
    "type": "whitelist",
    "members":
    [
      "example@domain.com"
    ]
  }
```
 if `testMode` is `true` then no signatures will be set at GSuite and the manual overrides from `overrides.json` will not be marked as done. Highly recommended to always run the script with testMode active first.
 
 `type` can be either `whitelist` or `blacklist`. Depending on this value, the aliases enumerated in `members` will either be the only ones considered for updating or will be ignored, even if there are updates to their data.   
 
 * `addresses.json` - an array of pairs like `id => address`, where the `id` can then be used in the `overrides.json` file if needed. Helps in case multiple users have the same address and it needs to be updated
 
## Logging

* Levels used: DEBUG -> INFO -> WARNING -> ERROR
* If the script is executed through the browser, INFO and above levels are displayed within the browser JS console.
* If the script is executed through the command line, INFO and above levels are displayed in the console directly
* No matter how the script is executed, ALL logging (DEBUG and above) is also saved in a file from the folder `logs`.
* If the preview mode is activated (and it is by default, can be changed in `index.php`), a preview of signature will be displayed in the browser, or the HTML will be pasted in the command line interface. Useful in combination with the test mode.
