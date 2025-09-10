<?php

declare(strict_types=1);

namespace Supseven\ThemeDev\ViewHelpers;

use Supseven\ThemeDev\Utility\LipsumGenerator;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Render a lorem-ipsum dummy text
 *
 * <code>
 * <html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
 *       xmlns:dev="http://typo3.org/ns/Supseven/ThemeDev/ViewHelpers"
 *       data-namespace-typo3-fluid="true">
 *
 * <!-- One line of text with 10 words -->
 * <dev:lipsum />
 *
 * <!-- Two lines, separated by a new line with 5 words each -->
 * <dev:lipsum paragraphCount="2" wordsPerParagraph="5" />
 *
 * <!-- Three lines, 10 words each, as list to iterate over -->
 * <f:for each="{dev:lipsum paragraphCount: 3, asArray: 1}" as="line">
 *     <p>{line}</p>
 * </f:for>
 * </html>
 * </code>
 *
 * @author Volker Kemeter <v.kemeter@supseven.at>
 */
class LipsumViewHelper extends AbstractViewHelper
{
    protected $escapeChildren = false;

    public function __construct(
        protected readonly LipsumGenerator $lipsumGenerator,
    ) {
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('paragraphCount', 'int', 'how many paragraphs to generate', false, 1);
        $this->registerArgument('wordsPerParagraph', 'int', 'how many words per paragraph', false, 10);
        $this->registerArgument('asArray', 'boolean', 'return as string or array', false, false);
    }

    public function render(): string|array
    {
        return $this->lipsumGenerator->generate($this->arguments['paragraphCount'], $this->arguments['wordsPerParagraph'], $this->arguments['asArray']);
    }
}
