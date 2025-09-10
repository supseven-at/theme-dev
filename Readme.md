# TYPO3 helpers for development

This package contains a small list of helper functions for TYPO3 for testing,
integration and development.

## Testdata generator

### As a service

```php
class MyClass {
    public function __construct(
        protected readonly \Supseven\ThemeDev\Utility\LipsumGenerator $lipsumGenerator,
    ) {
    }

    public function oneLine(): string
    {
        return $this->lipsumGenerator->generate();
    }

    public function twoParagraphs(): string
    {
        $html = '';

        foreach ($this->lipsumGenerator->generate(2, asArray: true) as $line) {
            $html .= "<p>$line</p>"
        }

        return $html;
    }
}
```

### As a view helper

```html
<html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
      xmlns:dev="http://typo3.org/ns/Supseven/ThemeDev/ViewHelpers"
      data-namespace-typo3-fluid="true">

<!-- One line of text with 10 words -->
<dev:lipsum />

<!-- Two lines, separated by a new line with 5 words each -->
<f:format.nl2br><dev:lipsum paragraphCount="2" wordsPerParagraph="5" /></f:format.nl2br>

<!-- Three lines, 10 words each, as list to iterate over -->
<f:for each="{dev:lipsum paragraphCount: 3, asArray: 1}" as="line">
    <p>{line}</p>
</f:for>

</html>
```

## Solr Indexer

Cleanup, add to index queue and run the queue. It requires that every document
has the `indexConfiguration` - name in a string field. By default, this field
is assumed to be named `type_stringS`. This can be customized with the `-f` or
`--field` option.

Examples:

```shell
# Index everything
typo3 solr:index

# Index everything with detailed log messages on stdout (disables progress bar)
typo3 solr:index -vvv

# Index all types only site main and microsite
typo3 solr:index -s main -s microsite

# Index only news on all sites
typo3 solr:index -t news

# Index only pages and news on site main and microsite
typo3 solr:index -s main -s microsite -t pages -t news
```
