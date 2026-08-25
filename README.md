# ReShare VuFind Driver

This repository contains a VuFind module and configuration for interacting with
ReShare consortial systems. The code targets VuFind 11.

## Module

Enable `ReShare` after VuFind's core modules. Copy the files from
`config/vufind` into the site's local `config/vufind` directory and customize
them for the installation.

## Theme

The `themes/reshare-bootstrap5` theme is a child of VuFind 11's core
`bootstrap5` theme. It does not depend on the Index Data Bootstrap 5 theme.
Install it in the VuFind themes directory and set `Site.theme` to
`reshare-bootstrap5` in the site's `config.ini`.

The theme adds the ReShare authentication fields, request-oriented OpenURL
button, account request details, linked access notes, and optional blank-request
links. All other presentation is inherited from VuFind so upstream Bootstrap 5
compatibility and accessibility fixes remain available.

## Configuration

`config/vufind/ReShare.ini` contains the ReShare API, request-policy, state-label,
and institution settings. The `[members]` map supplies the institutions shown
by the ReShare login form.

Blank-request links are disabled by default. To enable them, configure
`[BlankRequests]` with the external form's base URL and identifiers. The theme
adds loan and copy links to the account menu and a generic blank-request link to
the no-results page. Patron identifiers are URL encoded before being sent.

### Harvest config for Reservoir
ReShare typically uses [Reservoir](https://github.com/indexdata/reservoir) for shared holdings. 
The following settings are recommened for using VuFind's harvest worklflow to populate the index:

Use this configuration in `VUFIND_LOCAL_DIR/harvest/oai.ini` to configure a harvest from reservoir
```
[reshare]
url = "https://<OKAPI_HOSTNAME>/_/invoke/tenant/<RESERVOIR_TENANT_ID>/reservoir/oai?limit=2000"
metadataPrefix = marcxml
combineRecords = true
combineRecordsTag = <collection>
```

A typical Solrmarc import map (marc_local.properties) may look like this:

```
id = 999i, first
collection = "ReShare"
institution = 999s
building = 999s

lending_status = 999p

first_indexed = custom, getFirstIndexed(001)
last_indexed = custom, getLastIndexed(001)
```

### Patron API
TODO
