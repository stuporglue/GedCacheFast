<?php

// https://github.com/stuporglue/GedCacheFast
//
// Copyright 2013 Michael Moore <stuporglue@gmail.com>
//
// Licensed under the MIT License

namespace GedCacheFast;

/*
 * A record for a single line from a GEDCOM file
 */
class Record
{

    /**
     * The parser instance
     */
    protected $_parser;

    /**
     * The parent record. Will be unset for 0-level lines
     */
    protected $_parentRecord;

    /**
     * What level is this record
     */
    protected $_level;

    /**
     * What type is this record
     */
    protected $_type;

    /**
     * What is the ID for this record? 
     */
    protected $_id;

    /**
     * What's the value of this record?
     */
    protected $_value;

    /**
     * All this record's children records
     */
    protected $_childRecs = Array();

    /**
     * The database ID (if set)
     */
    protected $_dbId;

    /**
     * The record's file posiiton
     */
    protected $_position;

    /**
     * Make a new record
     */
    function __construct($parsedLineArray,\GedCacheFast\Record $parent = NULL){
        foreach($parsedLineArray as $k => $v){
            if($v !== FALSE){
                $varname = "_$k";
                $this->$varname = $v;
            }
        }

        if(!is_null($parent)){
            $this->_parentRecord = $parent;
        }
    }

    /**
     * Get the current record's parent record
     *
     * @return \GedCacheFast\Record or undefined
     */
    function getParent($cachedOnly = FALSE){
        if(isset($this->_parentRecord) || $cachedOnly){
            return $this->_parentRecord;
        }

        if($this->_level != 0){
            // when loading with low mem or database we might not have the parent record loaded
            if(isset($this->_position) && $this->_parser){
                $top = $this->_parser->lowMemTopParentFor($this->_position);
                $top->adoptClone($this);
            }
        }
        return $this->_parentRecord;
    }

    /**
     * Set the parent record
     *
     * @param required Record object
     */
    function setParent($parent){
        $this->_parentRecord = $parent;
    }

    /**
     * Given a record, find an match for that record in our child tree and replace our existing child with this one
     * Set parent records in the adopted child so it knows who its parent record is
     *
     * Assumes that $this knows who its parents and children are
     *
     * Does a breadth-first non-recursive search of all children
     */
    function adoptClone($newChild){
        $childRecs = $this->_childRecs;
        while(count($childRecs) > 0){
            $currentRecs = array_shift($childRecs);
            foreach($currentRecs as $idx => $existingChild){
                if($existingChild->match($newChild)){
                    $theParent = $existingChild->getParent();
                    $newChild->setParent($theParent);
                    $theParent->_childRecs[$newChild->getType()][$idx] = $newChild;
                    return TRUE;
                }

                // Add the new values to the childRecs queue
                foreach($existingChild->getChildRecs() as $type => $values){
                    if(isset($childRecs[$type])){
                        $childRecs[$type] += $values;
                    }else{
                        $childRecs[$type] = $values;
                    }
                }
            }
        }
        return FALSE;
    }

    /**
     * Check if critical values match
     */
    function match($other){
        return $this->_level == $other->getLevel() &&
            $this->_type == $other->getType() &&
            $this->_id  == $other->getId() && 
            $this->_ref == $other->getRef() && 
            $this->_value == $other->getValue();
    }

    /*
     * Add a new child record to this record
     *
     * @return The newly created record
     */
    function addChild($parsedLineArray){
        $child = new \GedCacheFast\Record($parsedLineArray,$this);
        $this->_childRecs[$parsedLineArray['type']][] = $child;
        return $child;
    }

    function dbId($newValue=NULL){
        if(!is_null($newValue)){
            $this->_dbId = $newValue;
        }
        return $this->_dbId;
    }

    function __call($func,$args){
        if(preg_match('/^get(.*)/',$func,$matches)){
            $tag = strtoupper($matches[1]);
            if($this->_type == $tag){
                return $this->_value;
            }

            $lower = strtolower($tag);

            if(array_key_exists("_$lower",get_object_vars($this))){
                return $this->{"_$lower"};
            }

            if(isset($this->_childRecs[$tag])){
                return $this->_childRecs[$tag];
            }
        }
    }

    function __toString(){
        return $this->_value;
    }

    /**
     * Get all the child arrays
     */
    function getChildRecs(){
        return $this->_childRecs;
    }
}
