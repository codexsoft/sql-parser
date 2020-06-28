<?php

namespace CodexSoft\SqlParser;

/**
 * Service class for parsing a large SQL string into an array of SQL statements
 *
 * @author François Zaninotto
 */
class SqlParser
{
    protected $delimiter = ';';
    protected $delimiterLength = 1;

    protected $sql = '';
    protected $len = 0;
    protected $pos = 0;

    /**
     * Sets the inner SQL string for this object.
     * Also resets the parsing cursor (see getNextStatement)
     *
     * @param string $sql The SQL string to parse
     */
    private function setSQL(string $sql): void
    {
        $this->sql = $sql;
        $this->pos = 0;
        $this->len = \strlen($sql);
    }

    ///**
    // * Gets the inner SQL string for this object.
    // *
    // * @return string The SQL string to parse
    // */
    //private function getSQL(): string
    //{
    //    return $this->sql;
    //}

    /**
     * Explodes a SQL string into an array of SQL statements.
     * @example
     * <code>
     * echo SqlParser::parseString("-- Table foo
     * DROP TABLE foo;
     * CREATE TABLE foo (
     *   id int(11) NOT NULL AUTO_INCREMENT,
     *   title varchar(255) NOT NULL,
     *   PRIMARY KEY (id),
     * ) ENGINE=InnoDB;");
     * // results in
     * // array(
     * //   "DROP TABLE foo;",
     * //   "CREATE TABLE foo (
     * //      id int(11) NOT NULL AUTO_INCREMENT,
     * //      title varchar(255) NOT NULL,
     * //      PRIMARY KEY (id),
     * //   ) ENGINE=InnoDB;"
     * // )
     * </code>
     * @param string $input The SQL code to parse
     *
     * @return array A list of SQL statement strings
     */
    public static function parseString($input): array
    {
        $parser = new self;
        $parser->setSQL($input);
        $parser->convertLineFeedsToUnixStyle();
        $parser->stripSQLCommentLines();

        return $parser->explodeIntoStatements();
    }

    /**
     * Explodes a SQL file into an array of SQL statements.
     * @example
     * <code>
     * echo SqlParser::parseFile('/var/tmp/foo.sql');
     * // results in
     * // array(
     * //   "DROP TABLE foo;",
     * //   "CREATE TABLE foo (
     * //      id int(11) NOT NULL AUTO_INCREMENT,
     * //      title varchar(255) NOT NULL,
     * //      PRIMARY KEY (id),
     * //   ) ENGINE=InnoDB;"
     * // )
     * </code>
     * @param string $file The absolute path to the file to parse
     *
     * @return array A list of SQL statement strings
     */
    public static function parseFile($file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        return self::parseString(\file_get_contents($file));
    }

    private function convertLineFeedsToUnixStyle(): void
    {
        $this->setSQL(str_replace(["\r\n", "\r"], "\n", $this->sql));
    }

    private function stripSQLCommentLines(): void
    {
        $this->setSQL(preg_replace([
            '#^\s*(//|--|\#).*(\n|$)#m',    // //, --, or # style comments
            '#^\s*/\*.*?\*/#s'              // c-style comments
        ], '', $this->sql));
    }

    /**
     * Explodes the inner SQL string into statements based on the SQL statement delimiter (;)
     *
     * @return array A list of SQL statement strings
     */
    private function explodeIntoStatements(): array
    {
        $this->pos = 0;
        $sqlStatements = [];
        while ($sqlStatement = $this->getNextStatement()) {
            $sqlStatements[] = $sqlStatement;
        }

        return $sqlStatements;
    }

    /**
     * Gets the next SQL statement in the inner SQL string,
     * and advances the cursor to the end of this statement.
     *
     * @return string A SQL statement
     */
    private function getNextStatement(): string
    {
        $isAfterBackslash = false;
        $isInString = false;
        $stringQuotes = '';
        $parsedString = '';
        $lowercaseString = ''; // helper variable for performance sake
        while ($this->pos <= $this->len) {
            $char = $this->sql[$this->pos] ?? '';
            //$char = isset($this->sql[$this->pos]) ? $this->sql[$this->pos] : '';
            // check flags for strings or escaper
            switch ($char) {
                case "\\":
                    $isAfterBackslash = true;
                    break;
                case "'":
                case '"':
                    if ($isInString && $stringQuotes === $char) {
                        if (!$isAfterBackslash) {
                            $isInString = false;
                        }
                    } elseif (!$isInString) {
                        $stringQuotes = $char;
                        $isInString = true;
                    }
                    break;
            }
            $this->pos++;
            if ($char !== "\\") {
                $isAfterBackslash = false;
            }
            if (!$isInString) {
                if (false !== strpos($lowercaseString, 'delimiter ')) {
                    // remove DELIMITER from string because it's a command-line keyword only
                    $parsedString = trim(str_ireplace('delimiter ', '', $parsedString));
                    // set new delimiter
                    $this->delimiter = $char;
                    // append other delimiter characters if any
                    while (isset($this->sql[$this->pos]) && $this->sql[$this->pos] !== "\n") {
                        $this->delimiter .= $this->sql[$this->pos++]; // increase position
                    }
                    $this->delimiter = trim($this->delimiter);
                    // store delimiter length for better performance
                    $this->delimiterLength = \strlen($this->delimiter);
                    // delimiter has changed so return current sql if any
                    if ($parsedString) {
                        return $parsedString;
                    }

                    // reset helper variable
                    $lowercaseString = '';
                    continue;

                }
                // get next characters if we have multiple characters in delimiter
                $nextChars = '';
                for ($i = 0; $i < $this->delimiterLength - 1; $i++) {
                    if (!isset($this->sql[$this->pos + $i])) {
                        break;
                    }
                    $nextChars .= $this->sql[$this->pos + $i];
                }
                // check for end of statement
                if ($char.$nextChars === $this->delimiter) {
                    $this->pos += $i; // increase position
                    return trim($parsedString);
                }
                // avoid using strtolower on the whole parsed string every time new character is added
                // there is also no point in adding characters which are in the string
                $lowercaseString .= strtolower($char);
            }
            $parsedString .= $char;
        }
        return trim($parsedString);
    }

}
