# processProquest

A library for ingesting ProQuest ETDs into Islandora/Fedora DAM. This script is heavily tailored for the Boston College Library's ETD workflow.

# Requirements
The following packages are required to run this script:
 * [fop](https://xmlgraphics.apache.org/fop/)
 * php
 * php-dom
 * php-zip
 * php-curl
 * imagemagick
 * xpdf
 * poppler-utils

Also recommended is the [freesans-font](https://github.com/opensourcedesign/fonts) family.

Access to a live Fedora DAM instance is required.

# Installation
Clone this repository within your `drupal/sites/all/libraries/` directory.

```
cd /var/www/html/drupal/sites/all/libraries
git clone https://github.com/BCDigLib/processProquest
```

# Usage

The script can be invoked with in the following way.

```
cd /var/www/html/drupal/sites/all/libraries
php index.php processProquest.ini
```

> An optional configuration file can be given as the second argument. If you leave out this configuration argument then the script will look for a default "processProquest.ini" file.

## Debug

Debug mode will execute the script but will **ignore** the following tasks:
* create a valid PID (a random number will be used)
* save datastreams (datastreams are generated but not saved)
* send notification email (email notification will be generated but not sent)

There are two ways to enable debugging. First, edit the configuration file and set `[script] debug` to "true". 

The second way is to set an environmental variable on the command line when invoking this script.

Example: 

```
PROCESSPROQUEST_DEBUG=true php index.php processProquest.ini 
```


# Configuration

Copy the processProquest-sample.ini file and rename it so processProquest.ini

Fill in the following settings.

```
[ftp]
server     = "ftp_server.example.edu"  // ftp server where ETDs are deposited
user       = "ftpUser"                 // ftp user
password   = "<password>"              // ftp user password
localdir   = "/tmp"                    // local directory for processing files, e.g., /tmp
fetchdir   = ""                        // directory to find ETDs on the ftp server. relative paths only
processdir = "processed"               // directory to place ETDs on the ftp server on success. relative paths only
faildir    = "failed"                  // directory to place ETDs on the ftp server on failure. relative paths only

[xslt]
xslt       = "/opt/Proquest_MODS.xsl"  // location of various files or XPath expressions
label      = "xsl/getLabel.xsl"
oa         = "/DISS_submission/DISS_repository/DISS_acceptance/text()"
embargo    = "/DISS_submission/DISS_repository/DISS_delayed_release/text()"
creator    = "/mods:mods/mods:name[@type='personal'][@usage='primary']/mods:displayForm/text()"
supplement = "/DISS_submission/DISS_content/DISS_attachment"
splash     = "/path/to/splash/page/stylesheet/splash.xsl"

[fedora]
url        = "localhost:8080/fedora/"  // Fedora DAM location
username   = "fedoraUser"              // Fedora user
password   = "fedoraPassword"          // Fedora user password
namespace  = "bc-ir"                   // Namespace for PIDs, e.g., bc-ir:1000

[notify]
email      = "bar@foo.edu,baz@foo.edu" // email recipients, comma-separated

[log]
location   = "/path/to/logs"           // directory to save script output logs

[packages]
tuque      = "/opt/libraries/tuque"    // location of system packages and libraries
fop        = "/opt/fop/fop"
fop_config = "/opt/boston_college/data/fop/cfg.xml"
convert    = "/usr/bin/convert"
pdftk      = "/usr/bin/pdftk"
pdftotext  = "/usr/bin/pdftotext"

[script]
debug      = "false"                   // run this script in Debug mode?
```
