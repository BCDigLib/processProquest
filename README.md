# processProquest

A library for ingesting ProQuest ETDs into Islandora/Fedora DAM. This script is heavily tailored for the Boston College Library's ETD workflow.

# Requirements
The following packages are required to run this script:
 * [fop](https://xmlgraphics.apache.org/fop/)
 * php
 * php-dom
 * php-zip
 * php-curl
 * [composer](https://getcomposer.org/)
 * imagemagick
 * poppler-utils

Also recommended is the [freesans-font](https://github.com/opensourcedesign/fonts) family.

Access to a live Fedora DAM instance is required.

## Development environment
The following packages will be very useful for development, debugging, and testing:
 * php-cli
 * php-json
 * php-mbstring
 * php-xml
 * php-pcov
 * php-xdebug
 * xpdf
 * phpunit

# Installation
Clone this repository within your `drupal/sites/all/libraries/` directory.

```
cd /var/www/html/drupal/sites/all/libraries
git clone https://github.com/BCDigLib/processProquest
```

Next, install packages with composer.
```
php composer.phar install
```

# Usage

The script can be invoked with in the following way.

```
cd /var/www/html/drupal/sites/all/libraries
php index.php processProquest.ini
```

> Note: An optional configuration file can be given as the second argument. If you leave out this configuration argument then the script will look for a default "processProquest.ini" file.

## Debug

Debug mode will execute the script but will **ignore** the following tasks:
* create a valid PID (a random number will be used)
* save datastreams (datastreams are generated but not saved)
* send notification email (email notification will be generated but not sent)

There are two ways to enable debugging. 

1. Edit the configuration file and set:
```
[script] 
debug = "true"
``` 

2. Set an environmental variable on the command line when invoking this script.

Example:
```
PROCESSPROQUEST_DEBUG=true php index.php processProquest.ini 
```
> Note: The environmental variable will overwrite the debug value set in the configuration file.


# Configuration

Copy the processProquest-sample.ini file and rename it so processProquest.ini

Fill in the following settings.

```
[ftp]
server     = "ftp_server.example.edu"   ; ftp server where ETDs are deposited
user       = "ftpUser"                  ; ftp user
password   = "<password>"               ; ftp user password
localdir   = "/tmp"                     ; local directory for processing files, e.g., /tmp
fetchdir   = "~/"                       ; directory to find ETDs on the ftp server.
processdir = "~/processed/"             ; directory to place ETDs on the ftp server on success.
faildir    = "~/failed/"                ; directory to place ETDs on the ftp server on failure.
manualdir  = "~/needs_attention/"       ; directory to place ETDs that need manual processing.
file_regex = "etdadmin_upload*"         ; ETD zip file match regular expression

[xslt]
xslt       = "/opt/Proquest_MODS.xsl"   ; location of various files or XPath expressions
label      = "xsl/getLabel.xsl"
oa         = "/DISS_submission/DISS_repository/DISS_acceptance/text()"
embargo    = "/DISS_submission/DISS_repository/DISS_delayed_release/text()"
creator    = "/mods:mods/mods:name[@type='personal'][@usage='primary']/mods:displayForm/text()"
supplement = "/DISS_submission/DISS_content/DISS_attachment"
splash     = "/path/to/splash/page/stylesheet/splash.xsl"

[fedora]
url        = "localhost:8080/fedora/"   ; Fedora DAM location
username   = "fedoraUser"               ; Fedora user
password   = "fedoraPassword"           ; Fedora user password
namespace  = "bc-ir"                    ; Namespace for PIDs, e.g., bc-ir:1000

[islandora]
root_url   = "https://foo.example.edu"  ; Root url for Islandora instance
path       = "/islandora/object/"       ; Path for an Islandora record

[notify]
email      = "bar@foo.edu,baz@foo.edu"  ; email recipients, comma-separated

[log]
location   = "/path/to/logs"            ; directory to save script output logs

[packages]
tuque      = "/opt/libraries/tuque"     ; location of system packages and libraries
fop        = "/opt/fop/fop"
fop_config = "/opt/boston_college/data/fop/cfg.xml"
convert    = "/usr/bin/convert"
pdftk      = "/usr/bin/pdftk"
pdftotext  = "/usr/bin/pdftotext"

[script]
debug      = "false"                    ; run this script in Debug mode?
```

# Tests

Unit tests can be found in the [tests](tests) directory. 

PHPUnit should be installed using composer, otherwise install manually.

Run tests using this command

```
./vendor/bin/phpunit tests
```
