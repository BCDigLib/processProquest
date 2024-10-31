# ETD test files

The following zip files are provided for testing purposes. Unless noted, each zip file contains a sample PDF, and a well-formatted XML file.

| File name                   | Has Embargo | Has supplemental files | Notes |
| --------------------------- | ------------| -----------------------|-------|
| etdadmin_upload_100000.zip  | no          | no                     |       |
| etdadmin_upload_200000.zip  | yes         | no                     |       |
| etdadmin_upload_300000.zip  | no          | yes                    |       |
| etdadmin_upload_400000.zip  | n/a         | n/a                    | empty zip file |
| not_a_real_zip_file.zip     | n/a         | n/a                    | text file labeled as a zip file |

## TODO

Create test zip files that:
* are missing either a PDF or XML file.
* are missing an OA agreement flag.
* has a PDF or XML file that lack the `0016` name substring identifier.
* has supplemental files that are not located in a subdirectory.