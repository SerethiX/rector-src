<?php

declare(strict_types=1);

namespace Rector\BetterPhpDocParser\Printer;

use Nette\Utils\Strings;
use PhpParser\Comment;
use PhpParser\Node\Stmt\InlineHTML;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocChildNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ThrowsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\BetterPhpDocParser\PhpDocNodeVisitor\ChangedPhpDocNodeVisitor;
use Rector\BetterPhpDocParser\ValueObject\PhpDocAttributeKey;
use Rector\BetterPhpDocParser\ValueObject\StartAndEnd;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\Util\StringUtils;
use Rector\PhpDocParser\PhpDocParser\PhpDocNodeTraverser;

/**
 * @see \Rector\Tests\BetterPhpDocParser\PhpDocInfo\PhpDocInfoPrinter\PhpDocInfoPrinterTest
 */
final class PhpDocInfoPrinter
{
    /**
     * @var string
     * @see https://regex101.com/r/Ab0Vey/1
     */
    private const CLOSING_DOCBLOCK_REGEX = '#\*\/(\s+)?$#';

    /**
     * @var string
     * @see https://regex101.com/r/5fJyws/1
     */
    private const CALLABLE_REGEX = '#callable(\s+)\(#';

    /**
     * @var string[]
     */
    private const DOCBLOCK_STARTS = ['//', '/**', '/*', '#'];

    /**
     * @var string Uses a hardcoded unix-newline since most codes use it (even on windows) - otherwise we would need to normalize newlines
     */
    private const NEWLINE_WITH_ASTERISK = "\n" . ' *';

    private int $tokenCount = 0;

    private int $currentTokenPosition = 0;

    /**
     * @var mixed[]
     */
    private array $tokens = [];

    private ?PhpDocInfo $phpDocInfo = null;

    private readonly PhpDocNodeTraverser $changedPhpDocNodeTraverser;

    public function __construct(
        private readonly EmptyPhpDocDetector $emptyPhpDocDetector,
        private readonly DocBlockInliner $docBlockInliner,
        private readonly RemoveNodesStartAndEndResolver $removeNodesStartAndEndResolver,
        private readonly ChangedPhpDocNodeVisitor $changedPhpDocNodeVisitor,
    ) {
        $changedPhpDocNodeTraverser = new PhpDocNodeTraverser();
        $changedPhpDocNodeTraverser->addPhpDocNodeVisitor($this->changedPhpDocNodeVisitor);

        $this->changedPhpDocNodeTraverser = $changedPhpDocNodeTraverser;
    }

    public function printNew(PhpDocInfo $phpDocInfo): string
    {
        $docContent = (string) $phpDocInfo->getPhpDocNode();
        if ($phpDocInfo->isSingleLine()) {
            return $this->docBlockInliner->inline($docContent);
        }

        if ($phpDocInfo->getNode() instanceof InlineHTML) {
            return '<?php' . PHP_EOL . $docContent . PHP_EOL . '?>';
        }

        return $docContent;
    }

    /**
     * As in php-parser
     *
     * ref: https://github.com/nikic/PHP-Parser/issues/487#issuecomment-375986259
     * - Tokens[node.startPos .. subnode1.startPos]
     * - Print(subnode1)
     * - Tokens[subnode1.endPos .. subnode2.startPos]
     * - Print(subnode2)
     * - Tokens[subnode2.endPos .. node.endPos]
     */
    public function printFormatPreserving(PhpDocInfo $phpDocInfo): string
    {
        if ($phpDocInfo->getTokens() === []) {
            // completely new one, just print string version of it
            if ($phpDocInfo->getPhpDocNode()->children === []) {
                return '';
            }

            if ($phpDocInfo->getNode() instanceof InlineHTML) {
                return '<?php' . PHP_EOL . $phpDocInfo->getPhpDocNode() . PHP_EOL . '?>';
            }

            return (string) $phpDocInfo->getPhpDocNode();
        }

        $phpDocNode = $phpDocInfo->getPhpDocNode();
        $this->tokens = $phpDocInfo->getTokens();

        $this->tokenCount = $phpDocInfo->getTokenCount();
        $this->phpDocInfo = $phpDocInfo;

        $this->currentTokenPosition = 0;

        $phpDocString = $this->printPhpDocNode($phpDocNode);

        // hotfix of extra space with callable ()
        return Strings::replace($phpDocString, self::CALLABLE_REGEX, 'callable(');
    }

    /**
     * @return Comment[]
     */
    public function printToComments(PhpDocInfo $phpDocInfo): array
    {
        $printedPhpDocContents = $this->printFormatPreserving($phpDocInfo);
        return [new Comment($printedPhpDocContents)];
    }

    private function getCurrentPhpDocInfo(): PhpDocInfo
    {
        if (! $this->phpDocInfo instanceof PhpDocInfo) {
            throw new ShouldNotHappenException();
        }

        return $this->phpDocInfo;
    }

    private function printPhpDocNode(PhpDocNode $phpDocNode): string
    {
        // no nodes were, so empty doc
        if ($this->emptyPhpDocDetector->isPhpDocNodeEmpty($phpDocNode)) {
            return '';
        }

        $output = '';

        // node output
        $nodeCount = count($phpDocNode->children);

        foreach ($phpDocNode->children as $key => $phpDocChildNode) {
            $output .= $this->printDocChildNode($phpDocChildNode, $key + 1, $nodeCount);
        }

        $output = $this->printEnd($output);

        // fix missing start
        if (! $this->hasDocblockStart($output) && $output !== '') {
            $output = '/**' . $output;
        }

        // fix missing end
        if (str_starts_with($output, '/**') && ! StringUtils::isMatch($output, self::CLOSING_DOCBLOCK_REGEX)) {
            $output .= ' */';
        }

        return str_replace(" \n", "\n", $output);
    }

    private function hasDocblockStart(string $output): bool
    {
        foreach (self::DOCBLOCK_STARTS as $docblockStart) {
            if (str_starts_with($output, $docblockStart)) {
                return true;
            }
        }

        return false;
    }

    private function printDocChildNode(
        PhpDocChildNode $phpDocChildNode,
        int $key = 0,
        int $nodeCount = 0
    ): string {
        $output = '';

        $shouldReprintChildNode = $this->shouldReprint($phpDocChildNode);

        if ($phpDocChildNode instanceof PhpDocTagNode &&
            ($shouldReprintChildNode && (
                $phpDocChildNode->value instanceof ParamTagValueNode ||
                $phpDocChildNode->value instanceof ThrowsTagValueNode ||
                $phpDocChildNode->value instanceof VarTagValueNode ||
                $phpDocChildNode->value instanceof ReturnTagValueNode ||
                $phpDocChildNode->value instanceof PropertyTagValueNode
            ))) {
            // the type has changed → reprint
            $phpDocChildNodeStartEnd = $phpDocChildNode->getAttribute(PhpDocAttributeKey::START_AND_END);

            // bump the last position of token after just printed node
            if ($phpDocChildNodeStartEnd instanceof StartAndEnd) {
                $this->currentTokenPosition = $phpDocChildNodeStartEnd->getEnd();
            }

            return $this->standardPrintPhpDocChildNode($phpDocChildNode);
        }

        /** @var StartAndEnd|null $startAndEnd */
        $startAndEnd = $phpDocChildNode->getAttribute(PhpDocAttributeKey::START_AND_END);

        if ($startAndEnd instanceof StartAndEnd && ! $shouldReprintChildNode) {
            $isLastToken = $nodeCount === $key;

            // correct previously changed node
            $this->correctPreviouslyReprintedFirstNode($key, $startAndEnd);

            $output = $this->addTokensFromTo(
                $output,
                $this->currentTokenPosition,
                $startAndEnd->getEnd(),
                $isLastToken
            );

            $this->currentTokenPosition = $startAndEnd->getEnd();

            return rtrim($output);
        }

        if ($startAndEnd instanceof StartAndEnd) {
            $this->currentTokenPosition = $startAndEnd->getEnd();
        }

        $standardPrintedPhpDocChildNode = $this->standardPrintPhpDocChildNode($phpDocChildNode);
        return $output . $standardPrintedPhpDocChildNode;
    }

    private function printEnd(string $output): string
    {
        $lastTokenPosition = $this->getCurrentPhpDocInfo()
            ->getPhpDocNode()
            ->getAttribute(PhpDocAttributeKey::LAST_PHP_DOC_TOKEN_POSITION);

        if ($lastTokenPosition === null) {
            $lastTokenPosition = $this->currentTokenPosition;
        }

        if ($lastTokenPosition === 0) {
            return $output . "\n */";
        }

        return $this->addTokensFromTo($output, $lastTokenPosition, $this->tokenCount, true);
    }

    private function addTokensFromTo(string $output, int $from, int $to, bool $shouldSkipEmptyLinesAbove): string
    {
        // skip removed nodes
        $positionJumpSet = [];

        $removedStartAndEnds = $this->removeNodesStartAndEndResolver->resolve(
            $this->getCurrentPhpDocInfo()
                ->getOriginalPhpDocNode(),
            $this->getCurrentPhpDocInfo()
                ->getPhpDocNode(),
            $this->tokens
        );

        foreach ($removedStartAndEnds as $removedStartAndEnd) {
            $positionJumpSet[$removedStartAndEnd->getStart()] = $removedStartAndEnd->getEnd();
        }

        // include also space before, in case of inlined docs
        if (isset($this->tokens[$from - 1]) && $this->tokens[$from - 1][1] === Lexer::TOKEN_HORIZONTAL_WS) {
            --$from;
        }

        // skip extra empty lines above if this is the last one
        if ($shouldSkipEmptyLinesAbove &&
            \str_contains((string) $this->tokens[$from][0], PHP_EOL) &&
            \str_contains((string) $this->tokens[$from + 1][0], PHP_EOL)
        ) {
            ++$from;
        }

        return $this->appendToOutput($output, $from, $to, $positionJumpSet);
    }

    /**
     * @param array<int, int> $positionJumpSet
     */
    private function appendToOutput(string $output, int $from, int $to, array $positionJumpSet): string
    {
        for ($i = $from; $i < $to; ++$i) {
            while (isset($positionJumpSet[$i])) {
                $i = $positionJumpSet[$i];
            }

            $output .= $this->tokens[$i][0] ?? '';
        }

        return $output;
    }

    private function correctPreviouslyReprintedFirstNode(int $key, StartAndEnd $startAndEnd): void
    {
        if ($this->currentTokenPosition !== 0) {
            return;
        }

        if ($key === 1) {
            return;
        }

        $startTokenPosition = $startAndEnd->getStart();

        $tokens = $this->getCurrentPhpDocInfo()
            ->getTokens();
        if (! isset($tokens[$startTokenPosition - 1])) {
            return;
        }

        $previousToken = $tokens[$startTokenPosition - 1];
        if ($previousToken[1] === Lexer::TOKEN_PHPDOC_EOL) {
            --$startTokenPosition;
        }

        $this->currentTokenPosition = $startTokenPosition;
    }

    private function shouldReprint(PhpDocChildNode $phpDocChildNode): bool
    {
        $this->changedPhpDocNodeTraverser->traverse($phpDocChildNode);
        return $this->changedPhpDocNodeVisitor->hasChanged();
    }

    private function standardPrintPhpDocChildNode(PhpDocChildNode $phpDocChildNode): string
    {
        $printedNode = (string) $phpDocChildNode;

        if ($this->getCurrentPhpDocInfo()->isSingleLine()) {
            return ' ' . $printedNode;
        }

        return self::NEWLINE_WITH_ASTERISK . ($printedNode === '' ? '' : ' ' . $printedNode);
    }
}
