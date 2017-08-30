# processProquest

A library for ingesting ProQuest ETDs to Islandora.

Usage: sudo php index.php processProquest.ini

Requires config file processProquest.ini with the following params:

```
[ftp]
server     = ftp_server.example.edu
user       = ftpUser
password   = ftpPassword
localdir   = /path/to/tmp/output/directory
fetchdir   = (leave blank if files are in home dir)
[xslt]
xslt       = /path/to/proquest/crosswalk/Proquest_MODS.xsl
label      = xsl/getLabel.xsl
oa         = "/DISS_submission/DISS_repository/DISS_acceptance/text()"
embargo    = "/DISS_submission/DISS_repository/DISS_delayed_release/text()"
creator    = "/mods:mods/mods:name[@type='personal'][@usage='primary']/mods:displayForm/text()"
supplement = "/DISS_submission/DISS_content/DISS_attachment"
splash     = "path/to/splash/page/stylesheet/splash.xsl"
[fedora]
url        = "islandora_server.example.edu:8080/fedora/"
username   = "fedoraUser";
password   = "fedoraPassword";
namespace  = bc-ir
[notify]
email      = notify.me@example.edu
```
