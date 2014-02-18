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
            preg_match('|(\d+)\s+(\S+)(.*)|',$rawline,$matches);
            $line = array_map('trim',$matches);
            array_shift($line);

            // not a valid line, skip it
            if(count($line) < 2){
                continue;
            }

            // Remove empty matches from the .* above
            if($line[2] == ''){
                array_pop($line);
            }

            unset($level);
            unset($type);
            $value = FALSE;

            // Find the line level, the type and value
            $level = (int)$line[0];
            if(preg_match('|@(.*)@|',$line[1],$matches)){
                $value = $matches[1];
                if(count($line) === 3){
                    $type = $line[2];
                }
            }else{
                $type = $line[1];
                if(count($line) === 3){
                    $value = preg_replace('|@(.*)@|',"\1",$line[2]);
                }
            }

            // Find the record the current line belongs in
            while(isset($currentRecord) && $level <= $currentRecord->getLevel()){
                $currentRecord = $currentRecord->getParent();
            }

            if(!isset($currentRecord)){
                $currentRecord = new \GedCacheFast\Record($level,$type,$value);

                // Make records arrays if needed
                if(!array_key_exists($type,$this->_records)){
                    $this->_records[$type] = Array();
                }
                if($value !== FALSE && !array_key_exists($value,$this->_records[$type])){
                    $this->_records[$type][$value] = Array();
                }

                // Add to records array
                if($value !== FALSE){
                    $this->_records[$type][$value] = $currentRecord;
                }else{
                    $this->_records[$type][] = $currentRecord;
                }
            }else{
                $currentRecord = $currentRecord->addChild($level,$type,$value);
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
}
