GedCacheFast
============

A hopefully fast, low-memory, lenient PHP GEDCOM parser that can cache to PDO database

Status
------
Successfully parses the two different GEDCOM files I have tested it with. 


Goals
-----
Not all goals have been achieved yet.

What this parser does: 

* Parses the GEDCOM file with low memory usage
* Optionally caches the GEDCOM file into a PDO database 
* Uses the PDO database instead of re-parsing the file if possible
* Allows quick access to 0-level GEDCOM objects
* Tries to not use much memory

What this parser doesn't do: 

* Know anything about valid GEDCOM tags or structure
* Write GEDCOM files

Use Case
--------
This parser is ideal parsing large potentially non-standard GEDCOM files in low-memory 
environments where the GEDCOM can be cached in a faster database for subsequent 
lookups. It could also be useful for deploying genealogy software into unknown
environments where a worst case scenario must be assumed.

Details
-------
This parser expects that knowledge of how to traverse a GEDCOM is baked into 
the application, not the parser. This gedcom will treat all tags the same, 
except: 

Tags which are at the 0-level and have an ID in them (@identifier@) will become 
new top level objects

Tags which are NOT at the top level and have an ID in them will reference a 
top level object of the given ID
CONT/CONC will be respected 

This parser assumes that anything matching the following two patterns are valid GEDCOM lines: 

    \s*\d+\s+\S+\s*

    \s*\d+\s+\S+\s+.+\s*
