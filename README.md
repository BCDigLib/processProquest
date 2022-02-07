# processProquest

A library for ingesting ProQuest ETDs into Islandora.

# Usage

`sudo php index.php processProquest.ini`

## Debug

Edit [index.php](index.php) and change the following line from `false` to `true` to run the script without saving the record into Fedora.

```$debug = true;```


# Script location

`/var/www/html/drupal/sites/all/libraries/processProquest`

# Configuration

Requires config file processProquest.ini with the following params:

```
[ftp]
server     = ftp_server.example.edu
user       = ftpUser
password   = ftpPassword
localdir   = /path/to/tmp/output/directory
fetchdir   = (leave blank if files are in home dir)
processdir = /path/to/dir/on/ftp/server/for/successful/ingests
faildir    = /path/to/dir/on/ftp/server/for/failed/ingests
[xslt]
xslt       = /path/to/proquest/crosswalk/Proquest_MODS.xsl
label      = xsl/getLabel.xsl
oa         = "/DISS_submission/DISS_repository/DISS_acceptance/text()"
embargo    = "/DISS_submission/DISS_repository/DISS_delayed_release/text()"
creator    = "/mods:mods/mods:name[@type='personal'][@usage='primary']/mods:displayForm/text()"
supplement = "/DISS_submission/DISS_content/DISS_attachment"
splash     = "/path/to/splash/page/stylesheet/splash.xsl"
[fedora]
url        = "islandora_server.example.edu:8080/fedora/"
username   = "fedoraUser";
password   = "fedoraPassword";
namespace  = bc-ir
[notify]
email      = notify.me@example.edu
[log]
location   = "/path/to/logs/
```
