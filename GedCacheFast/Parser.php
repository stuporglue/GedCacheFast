<?php

// https://github.com/stuporglue/GedCacheFast
//
// Copyright 2013 Michael Moore <stuporglue@gmail.com>
//
// Licensed under the MIT License

namespace GedCacheFast;

class Parser
{
    /*
     * A PDO DB Connection object to a database of your choice
     */
    protected $_pdo             = FALSE;

    /*
     * The max line the parser has reached so far
     */
    protected $_position        = 0;

    /*
     * A cache of top-level object IDs and their line numbers 
     */
    protected $_cache           = Array();

    /*
     * The filehandle for the GEDCOM file
     */
    protected $_gedcom          = FALSE;

    /*
     * The next line 
     */
    protected $_next            = FALSE;

    /*
     * 0-level records
     */
    protected $_records         = Array();

    /*
     * File position for the current line
     */
    protected $_linePosition    = 0;


    /*
     * Create the Gedcom object
     * 
     * @param $filename (required, String) The file to parse
     *
     * @param $pdo (optional)
     */
    public function __construct($pdo = FALSE){
        $this->_pdo = $pdo;
    }

    /*
     * Parse everything
     *
     * If a PDO object was set in the constructor then save to the database
     * otherwise, save locally
     *
     * @return Boolean
     */
    public function parse($filename){
        if(!file_exists($filename)){
            throw new \Exception("GEDCOM file $filename doesn't exist");
        }
        $this->_gedcom = fopen($filename,'r');

        $currentRecord;
        while($rawline = $this->getLine()){
            $lineParts = $this->parseLine($rawline);

            // not a valid line, skip it
            if(!$lineParts){
                continue;
            }

            // Find the record the current line belongs in
            while(isset($currentRecord) && $lineParts['level'] <= $currentRecord->getLevel()){
                $currentRecord = $currentRecord->getParent();
            }

            // Top level record here
            if(!isset($currentRecord)){
                $currentRecord = new \GedCacheFast\Record($lineParts);
                $this->cacheRecord($lineParts,$currentRecord);
            }else{
                $currentRecord = $currentRecord->addChild($lineParts);
            }
        }

        print_r(array_keys($this->_records));
    }

    /**
     * Magic all the get* functions so we can handle unknown types
     */
    function __call($func,$args){
        print $func;
        print_r($args);
        print_r(func_get_args());

        if(preg_match('/getAll(.*)/',$func,$matches)){
            $ak = array_keys($this->_records);
            if(array_key_exists($matches[1],$this->_records)){
                return $this->records[$matches[1]];
            }else{
                return Array();
            }
        }
    }


    /*
     * Get a single line, taking into account CONT/CONC
     *
     * @return String or FALSE (if file ended)
     */
    private function getLine(){
        $line = FALSE;

        // Start with whatever line we had queued up from last time
        if($this->_next !== FALSE){
            $line = $this->_next;
            $this->_next = FALSE;
        }

        // No line yet, grab one
        if($line === FALSE && !feof($this->_gedcom)){
            $line = fgets($this->_gedcom);
        }

        // Keep reading until we have a complete line
        while(!feof($this->_gedcom) && $this->_next === FALSE){
            $this->_next = fgets($this->_gedcom);

            // Continued line. Include a newline
            if(preg_match('|\s+CONT\s+(.*)|',$this->_next,$matches) > 0){
                $line .= "\n" . $matches[1];  
                $this->_next = FALSE;
            }

            // Concatenated line. Do not include a newline
            if(preg_match('|\s+CONC\s+(.*)|',$this->_next,$matches) > 0){
                $line .= $matches[1];  
                $this->_next = FALSE;
            }
        }

        // End of the file AND _next processed
        if(feof($this->_gedcom) && $line === ''){
            $line = FALSE;
        }

        return $line;
    }

    /**
     * Parses a single gedcom record line (a line, plus the CONT/CONC lines)
     *
     * Supported formats are: 
     *
     * Level LABEL 
     * Level LABEL Value 
     * Level LABEL @REF@
     * Level @ID@ LABEL 
     * Level @ID@ LABEL Value
     *
     * @return FALSE if the line is invalid, an hash of line parts if the line good
     */
    private function parseLine($rawline){
        $line = Array(
            'level' => FALSE,
            'type' => FALSE,
            'id'    => FALSE,
            'ref'   => FALSE,
            'value' => FALSE
        );

        // * Level LABEL 
        if(preg_match("|^\s*(\d+)\s+([A-Z]+)\s*$|",$rawline,$matches)){
            $line['level'] = (int)$matches[1]; 
            $line['type'] = $matches[2];
            return $line;
        }

        // * Level LABEL Value 
        if(preg_match("|^\s*(\d+)\s+([A-Z]+)\s*(.*)$|",$rawline,$matches)){
            $line['level'] = (int)$matches[1]; 
            $line['type'] = $matches[2];
            $line['value'] = $matches[3];
            return $line;
        }

        // * Level LABEL @REF@
        if(preg_match("|^\s*(\d+)\s+([A-Z]+)\s*(.*)$|",$rawline,$matches)){
            $line['level'] = (int)$matches[1]; 
            $line['type'] = $matches[2];
            $line['ref'] = $matches[3];
            return $line;
        }

        // * Level @ID@ LABEL 
        if(preg_match("|^\s*(\d+)\s+@(.*?)@\s*([A-Z]+)$|",$rawline,$matches)){
            $line['level'] = (int)$matches[1]; 
            $line['id'] = $matches[2];
            $line['type'] = $matches[3];
            return $line;
        }

        // * Level @ID@ LABEL Value
        if(preg_match("|^\s*(\d+)\s+@(.*?)@\s*([A-Z]+)\s*(.*)$|",$rawline,$matches)){
            $line['level'] = (int)$matches[1]; 
            $line['id'] = $matches[2];
            $line['type'] = $matches[3];
            $line['value'] = $matches[4];
            return $line;
        }

        return FALSE;
    }

    /**
     * Cache a single record for later lookup.
     *
     * In the future this could be replaced with a version that only records file positions or something
     */
    private function cacheRecord($lineParts,$currentRecord){
        // Make records arrays if needed
        if(!array_key_exists($lineParts['type'],$this->_records)){
            $this->_records[$lineParts['type']] = Array();
        }
        if($lineParts['value'] !== FALSE && !array_key_exists($lineParts['value'],$this->_records[$lineParts['type']])){
            $this->_records[$lineParts['type']][$lineParts['value']] = Array();
        }

        // Add to records array
        if($lineParts['value'] !== FALSE){
            $this->_records[$lineParts['type']][$lineParts['value']] = $currentRecord;
        }else{
            $this->_records[$lineParts['type']][] = $currentRecord;
        }
    }
}
