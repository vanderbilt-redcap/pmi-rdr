# PmiRdrModule
Connects PMI REDCap to the RDR

## Configuration
This module has many configuration options. Please review them in `config.json`.

## `pmiRdrHardCodeValue` and `@DEFAULT` Action Tags
When using the "Push Record on Save" configuration, this module uses two action tags

### `pmiRdrHardCodeValue`
Using this tag like `pmiRdrHardCodeValue="???"` will hard-code a value in the API payload. 

### `@DEFAULT="???"`
You can also specify a default value that will be sent across the interface. 