
I am making a PHP class that takes a multi dimensionl bool filter as string and checks if a given "arg" matches.

First we need to parse the filter string char by char into a tree (multidim array) using best practises for such a parser but as simple as possible.

We need to have 2 variants for the tree parser

- one for "arg" is just a full text => the filter string contains no data field names
- one for "arg" is a nested record (multi dimensionl array) => the filter contains data field names that may be nested

See samples below for this.

Synonyms: When we check the filter string against a full text we also may use lists of sysnonyms for each filter word loaded from a yml file. Each word that has synonyms in the tree needs to be expanded with its synonyms connected with logical "or".

Then use the tree to check the text or record in a recursive way.

Also provide a sample html page with bootstrap 5.3 and 2 tabs: one for testing a filter string against a single full text and one for testing a filter string against a few records in. Make the filter and the data so that all syntax variants can be tried. Put the filter editable in a input on each tab and below that show a "success" badge in the full text sample, and a filterable table for the records.


Samples
----------------------------------------------------------

... the samples (see readme) ...


Full syntax
----------------------------------------------------------

... the syatax (see readme) ...
