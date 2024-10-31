# ETD test files

The following zip files are provided for testing purposes. Unless noted, each zip file contains a sample PDF, and a well-formatted XML file.

| File name                            | Valid ETD | Notes |
| ------------------------------------ | ----------|-------|
| etdadmin_upload_001_normal.zip       | ✓         | well-formed ETD |
| etdadmin_upload_002_embargoed.zip    | ✓         | is embargoed |
| etdadmin_upload_003_supplemental.zip | ✓         | contains supplemental files |
| etdadmin_upload_004_empty.zip        | ✗         | empty zip file |
| etdadmin_upload_005_bad_id.zip       | ✗         | filenames lack `0016` BC identifier |
| etdadmin_upload_006_no_xml.zip       | ✗         | no XML file in zip file |
| etdadmin_upload_007_no_pdf.zip       | ✗         | no PDF file in zip file |
| etdadmin_upload_008_no_oa.zip        | ✓         | no OA agreement |
| not_a_real_zip_file.zip              | ✗         | text file labeled as a zip file |

## TODO

Create test zip files that:
* has supplemental files that are not located in a subdirectory.