# PmiRdrModule
Connects PMI REDCap to the RDR

## Configuration
This module has many configuration options. Please review them in `config.json`.

## Action Tags
When using the "Push Record on Save" configuration, this module uses action tags

### `pmiRdrHardCodeValue`
Using this tag like `pmiRdrHardCodeValue="???"` will hard-code a value in the API payload.

### `@DEFAULT="???"`
You can also specify a default value that will be sent across the interface. 

### `pmiRdrJsonArray="1"`
This action tag is only available on "notes" REDCap field types.  This action tag will:
* check if if the rdr field is an array type
* If so, the module will convert this into a json array and save it to the REDCap field.  This is useful for fields that are arrays in the RDR, but REDCap does not have an array field type.