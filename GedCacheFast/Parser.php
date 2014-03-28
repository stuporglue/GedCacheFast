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
     * In Low-Mem mode only a single 0-level record is stored in memory at a time. 
     * Instead of storing records, file positions are stored for each record.
     * This allows us to seek to those records as needed. 
     *
     * This DOES incur at least double file seek times. Once to parse the file
     * and once to fetch the records
     */
    protected $_lowMem          = FALSE;

    /*
     * The gedcom file being parsed. This lets us cache multiple GEDCOMs in the same database
     */
    protected $_gedcomFile      = NULL;

    /*
     * The file position for the current line
     * 
     * Should be in sync with the value returned by getLine
     */
    protected $_position        = FALSE;

    /**
     * All the positions of top-level records in the file
     */
    protected $_top_positions = Array();


    /*
     * The file position for the next line
     *
     * Should be in sync with the $this->_next
     */
    protected $_nextPosition    = FALSE;

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
    var $_records         = Array();

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
    public function __construct($pdo = FALSE,$lowMem = NULL){
        $this->_pdo = $pdo;

        if($this->_pdo){
            $this->_insertCache = $this->_pdo->prepare("INSERT INTO cache (level,type,id,ref,value,data) VALUES (:level,:type,:id,:ref,:value,:data)");
        }

        // Use low-mem if specifically requested or if we have a database and the user didn't say not to
        if(($pdo != FALSE && is_null($lowMem)) || $lowMem){
            $this->_lowMem = TRUE;
        }
    }

    /*
     * Prep everything for parsing
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

        $this->_gedcomFile = $filename;
        $this->_gedcom = fopen($filename,'r');

        $currentRecord;
        while($rawline = $this->getLine()){
            $lineParts = $this->parseLine($rawline);

            // not a valid line, skip it
            if(!$lineParts){
                continue;
            }

            $lineParts['parser'] = $this;

            // Find the record the current line belongs in
            while(isset($currentRecord) && $lineParts['level'] <= $currentRecord->getLevel()){
                $currentRecord = $currentRecord->getParent();
            }

            // Top level record here
            if(!isset($currentRecord)){
                // 0-level record
                $this->_top_positions[] = $this->_position;
                $currentRecord = new \GedCacheFast\Record($lineParts);
            }else{
                $currentRecord = $currentRecord->addChild($lineParts);
            }

            $this->cacheRecord($lineParts,$currentRecord);
        }

        // Reset line/nextLine 
        $this->_next = FALSE;
        $this->_nextPosition = FALSE;

        return TRUE;
    }

    /**
     * Just parse and return the current record and its children records
     */
    function parseBranch(){
        $currentRecord;
        $returnRecord;
        while($rawline = $this->getLine()){
            $lineParts = $this->parseLine($rawline);

            // not a valid line, skip it
            if(!$lineParts){
                continue;
            }

            $lineParts['parser'] = $this;

            // Find the record the current line belongs in
            while(isset($currentRecord) && $lineParts['level'] <= $currentRecord->getLevel()){
                if($lineParts['level'] <= $returnRecord->getLevel()){
                    break 2;
                }else{
                    $currentRecord = $currentRecord->getParent();
                }
            }

            // Top level record here
            if(!isset($returnRecord)){
                // 0-level record
                $returnRecord = $currentRecord = new \GedCacheFast\Record($lineParts);
            }else{
                $currentRecord = $currentRecord->addChild($lineParts);
            }
        }

        // Reset line/nextLine 
        $this->_next = FALSE;
        $this->_nextPosition = FALSE;
        return $returnRecord;
    }

    /**
     * Magic all the get* functions so we can handle unknown types
     *
     * getAll$TAGNAME (getAllIndi, getAllFam, etc.)
     * getIndi($id)
     *
     */
    function __call($func,$args){

        if(preg_match('/^getAll(.*)$/',$func,$matches)){
            $tag = strtoupper($matches[1]); 
            if(isset($this->_records[$tag])){

                if($this->_lowMem){
                    $return = Array();
                    foreach($this->_records[$tag] as $id => $seek){
                        if(is_array($seek)){
                            foreach($seek as $seekid => $seekval){
                                $return[] = $this->lowMemLoad($seekval);
                            }
                        }else{
                            $return[$id] = $this->lowMemLoad($seek);
                        }
                    }
                    return $return;
                }

                return $this->_records[$tag];
            }else{
                return Array();
            }
        }else if(preg_match('/^get(.*)$/',$func,$matches)){
            $tag = strtoupper($matches[1]);
            if(isset($this->_records[$tag]) && count($args) > 0 && isset($this->_records[$tag][$args[0]])){
                if($this->_lowMem){
                    return $this->lowMemLoad($this->_records[$tag][$args[0]]);
                }
                return $this->_records[$tag][$args[0]];
            }
        }
    }

    /**
     * Given a file position, seek until a record with a lower
     * level is returned
     * @param $seekTo The position in the file to seek to
     *
     * @return GedCacheFast\Record
     */
    function lowMemLoad($seekTo){
        fseek($this->_gedcom,$seekTo,0);
        $record = $this->parseBranch(TRUE);
        return $record;
    }

    function lowMemTopParentFor($childRecord){
        // TODO: Replace with some sort of search function
        $useMe = 0;
        foreach($this->_top_positions as $position){
            if($position > $childRecord){
                return $this->lowMemLoad($useMe);
            }
            $useMe = $position;
        }
        return 0;
    }

    /*
     * Get a single line, taking into account CONT/CONC
     *
     * @return String or FALSE (if file ended)
     */
    private function getLine(){
        $line = FALSE;
        $startPosition = FALSE;

        // Start with whatever line we had queued up from last time
        if($this->_next !== FALSE){
            $line = $this->_next;
            $this->_next = FALSE;

            $startPosition = $this->_nextPosition;
            $this->_nextPosition = FALSE;
        }

        // No line yet, grab one
        if($line === FALSE && !feof($this->_gedcom)){
            $startPosition = ftell($this->_gedcom);
            $line = fgets($this->_gedcom);
        }

        $this->_position = $startPosition;

        // Keep reading until we have a complete line
        while(!feof($this->_gedcom) && $this->_next === FALSE){
            $this->_nextPosition = ftell($this->_gedcom);
            $this->_next = fgets($this->_gedcom);

            // Continued line. Include a newline
            if(preg_match('|\s+CONT\s+(.*)|',$this->_next,$matches) > 0){
                $line .= "\n" . $matches[1];  
                $this->_nextPosition = FALSE;
                $this->_next = FALSE;
            }

            // Concatenated line. Do not include a newline
            if(preg_match('|\s+CONC\s+(.*)|',$this->_next,$matches) > 0){
                $line .= $matches[1];  
                $this->_nextPosition = FALSE;
                $this->_next = FALSE;
            }
        }

        // End of the file AND _next processed
        if(feof($this->_gedcom) && $line === ''){
            $this->_position = FALSE;
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
            'position' => $this->_position,
            'level' => FALSE,
            'type' => FALSE,
            'id'    => NULL,
            'ref'   => NULL,
            'value' => NULL 
        );


        // * Level LABEL 
        if(preg_match("|^\s*(\d+)\s+([A-Z]+)\s*$|",$rawline,$matches)){
            $line['level'] = (int)$matches[1]; 
            $line['type'] = $matches[2];
            return $line;
        }

            // * Level LABEL @REF@
        if(preg_match("|^\s*(\d+)\s+([A-Z]+)\s*@(.*)@\s*$|",$rawline,$matches)){
            $line['level'] = (int)$matches[1]; 
            $line['type'] = $matches[2];
            $line['ref'] = $matches[3];
            return $line;
        }

        // * Level @ID@ LABEL 
        if(preg_match("|^\s*(\d+)\s+@(.*?)@\s*([A-Z]+)\s*$|",$rawline,$matches)){
            $line['level'] = (int)$matches[1]; 
            $line['id'] = $matches[2];
            $line['type'] = $matches[3];
            return $line;
        }

        // * Level LABEL Value 
        if(preg_match("|^\s*(\d+)\s+([A-Z]+)\s*(.*)\s*$|",$rawline,$matches)){
            $line['level'] = (int)$matches[1]; 
            $line['type'] = $matches[2];
            $line['value'] = $matches[3];
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
        // If we have a database, cache the record there
        // This won't quite work because we'd like to be able to search by any parameter
        // not just top-level things.
        if($this->_pdo){
            $lineParts['gedcom'] = $this->_gedcomFile;

            foreach($lineParts as $k => $v){
                $this->_insertCache->bindParam(":" . $k,$v);
            }
            $this->_insertCache->bindParam(":data",serialize($currentRecord));
            $this->_insertCache->execute();

            if($this->_lowMem){
                // In the DB + lowmem case we don't cache anything in memory at all
                return;
            }
        }

        // By default, if we've got a database we're going to switch to low-mem mode
        // In low-mem mode cache the file positions. 
        if($this->_lowMem){
            $currentRecord = $this->_position;
        }

        if(!is_null($lineParts['id'])){
            $this->_records[$lineParts['type']][$lineParts['id']] = $currentRecord;
        }else{
            $this->_records[$lineParts['type']][$lineParts['value']][] = $currentRecord;
        }
    }
}
