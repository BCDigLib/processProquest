# processProquest

A library for ingesting ProQuest ETDs into Islandora/Fedora DAM. This script is heavily tailored for the Boston College Library's ETD workflow.

# Installation
Clone this repository within your `drupal/sites/all/libraries/` directory.

```
cd /var/www/html/drupal/sites/all/libraries
git clone https://github.com/BCDigLib/processProquest
```

# Usage

```
cd /var/www/html/drupal/sites/all/libraries
php index.php processProquest.ini
```

## Debug

Edit [index.php](index.php) and change the following line from `false` to `true` to run the script in debug mode. 

```$debug = true;```

Debug mode will execute the script but will **ignore** the following tasks:
* create a valid PID (a random number will be used)
* save datastreams (datastreams are generated but not saved)
* send notification email (email notification will be generated but not sent)

# Configuration

Copy the processProquest-sample.ini file and rename it so processProquest.ini

Fill in the following settings.

```
[ftp]
server     = ftp_server.example.edu         // ftp server where ETDs are deposited
user       = ftpUser                        // ftp user
password   = ftpPassword                    // ftp user password
localdir   = /tmp                           // local directory for processing files, e.g., /tmp
fetchdir   =                                // directory to find ETDs on the ftp server. relative paths only
processdir = processed                      // directory to place ETDs on the ftp server on success. relative paths only
faildir    = failed                         // directory to place ETDs on the ftp server on failure. relative paths only

[xslt]
xslt       = /path/to/proquest/crosswalk/Proquest_MODS.xsl
label      = xsl/getLabel.xsl
oa         = "/DISS_submission/DISS_repository/DISS_acceptance/text()"
embargo    = "/DISS_submission/DISS_repository/DISS_delayed_release/text()"
creator    = "/mods:mods/mods:name[@type='personal'][@usage='primary']/mods:displayForm/text()"
supplement = "/DISS_submission/DISS_content/DISS_attachment"
splash     = "/path/to/splash/page/stylesheet/splash.xsl"

[fedora]
url        = "ir.example.edu:8080/fedora/"  // Fedora DAM location. use localhost for local instance
username   = "fedoraUser"                   // Fedora user
password   = "fedoraPassword"               // Fedora user password
namespace  = bc-ir                          // Namespace for PIDs, e.g., bc-ir:1000

[notify]
email      = notify.me@example.edu          // email recipients 

[log]
location   = /path/to/logs                  // directory to save script output logs
```
