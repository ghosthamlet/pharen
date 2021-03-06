<?php
interface IPharenSeq{
    public function first();
    public function rest();
    public function cons($item);
}

class PharenList implements IPharenSeq, Countable, ArrayAccess, Iterator{
    public $first;
    public $rest;
    public $length = Null;
    public $iterator_key = 0;
    public $iterator_el;
    public $arr;

    public static function create_from_array(&$xs){
        $cache = SplFixedArray::fromArray($xs);
        $reversed = array_reverse($xs, True);
        $last_el = array_shift($reversed);
        $len = count($xs);
        $el1 = new PharenCachedList($last_el, new PharenEmptyList, 1, $cache, $len-1);
        foreach($reversed as $i=>$x){
            $index = $len-($i+2);
            $el2 = $el1->cached_cons($x, $cache, $index);
            $el1 = $el2;
        }
        return $el1;
    }

    public static function seqify(&$xs){
        if(is_array($xs)){
            if(count($xs) === 0){
                return new PharenEmptyList;
            }else{
                return self::create_from_array($xs);
            }
        }else if(is_string($xs)){
            $splitted = str_split($xs);
            return self::create_from_array($splitted);
        }
    }

    public function __construct($first, $rest=null, $length=1){
        $this->first = $first;
        $this->rest = $rest;
        $this->length = $length;
        $this->iterator_el = $this;
    }

    public function seq(){
        return $this;
    }

    public function arr(){
        if($this->arr)
            return $this->arr;

        $arr = array();
        foreach($this as $val){
            $arr[] = $val;
        }
        $this->arr = $arr;
        return $arr;
    }

    public function count(){
        if($this->length){
            return $this->length;
        }else{
            $this->length = 1 + $this->rest()->count();
            return $this->length;
        }
    }

    public function offsetExists($offset){
        $list = $this;
        for($x=$offset; $x > 0 && $list !== Null; $x--){
            $list = $list->rest;
        }
        return $list !== Null;
    }

    public function offsetGet($offset){
        $list = $this;
        for($x=$offset; $x > 0; $x--){
            if($list instanceof PharenEmptyList){
                throw new OutOfRangeException;
            }
            $list = $list->rest;
        }
        return $list->first;
    }

    public function offsetSet($offset, $value){
    }

    public function offsetUnset($offset){
    }

    public function current(){
        return $this->iterator_el->first;
    }

    public function key(){
        return $this->iterator_key;
    }

    public function next(){
        $this->iterator_key++;
        $this->iterator_el = $this->iterator_el->rest;
    }

    public function rewind(){
        $this->iterator_key = 0;
        $this->iterator_el = $this;
    }

    public function valid(){
        return $this->iterator_el->length !== 0;
    }

    public function first(){
        return $this->first;
    }

    public function rest(){
        return $this->rest;
    }

    public function cons($value){
        return new PharenList($value, $this, $this->length+1);
    }

    public function cached_cons($value, $cached_array, $index){
        return new PharenCachedList($value, $this, $this->length+1, $cached_array, $index);
    }
}

class PharenCachedList extends PharenList{
    public $cached_array;
    public $index;

    public function __construct($value, $rest, $length, $cached_array, $index){
        parent::__construct($value, $rest, $length);
        $this->cached_array = $cached_array;
        $this->index = $index;
        $this->length = count($this->cached_array) - $index;
    }

    public function count(){
        return $this->length;
    }

    public function arr(){
        if($this->arr)
            return $this->arr;
        $this->arr = array_slice($this->cached_array->toArray(), $this->index);
        return $this->arr;
    }

    public function offsetExists($offset){
        return isset($this->cached_array[$this->index + $offset]);
    }

    public function offsetGet($offset){
        return $this->cached_array[$this->index + $offset];
    }

    public function offsetSet($offset, $value){
        $this->cached_array[$this->index+$offset] = $value;
    }

    public function flatten($delimeters=Null){
        if(is_null($delimeters)){
            $tokens = array();
        }else{
            $tokens = array($delimeters[0]);
        }
        foreach($this->cached_array as $el){
            if($el instanceof PharenCachedList){
                $tokens = array_merge($tokens, $el->flatten($el->delimiter_tokens));
            }else{
                $tokens[] = $el;
            }
        }
        if(!is_null($delimeters)){
            $tokens[] = $delimeters[1];
        }
        return $tokens;
    }
}

class PharenEmptyList extends PharenList{

    public function __construct(){
        $this->first = Null;
        $this->length = 0;
        $this->rest = $this;
    }
}

class PharenLazyList implements IPharenSeq{
    public $first = Null;
    public $rest = Null;
    public $length = Null;
    public $lambda;
    public $lambda_result = Null;

    public function __construct($lambda){
        $this->lambda = $lambda;
    }

    public function seq(){
        return $this;
    }

    public function first(){
        if($this->first){
            return $this->first;
        }else{
            $this->set_first_and_rest();
            return $this->first;
        }
    }

    public function set_first_and_rest(){
        list($lambda, $scope_id) = $this->lambda;
        $this->lambda_result = $lambda($scope_id);
        $this->first = $this->lambda_result->first();
        $this->rest = $this->lambda_result->rest();
    }

    public function rest(){
        if($this->rest){
            return $this->rest;
        }else{
            $this->set_first_and_rest();
            return $this->rest;
        }
    }

    public function count(){
        if($this->length){
            return $this->length;
        }else{
            $this->length = 1 + $this->rest()->count();
            return $this->length;
        }
    }

    public function cons($value){
        return new PharenList($value, $this, Null);
    }
}

class PharenHashMap implements Countable, ArrayAccess{
    public $hashmap;
    public $count;

    public function __construct($hashmap, $count=Null){
        $this->hashmap = $hashmap;
        if($count){
            $this->count = $count;
        }else{
            $this->count = count($hashmap);
        }
    }

    public function assoc($key, $val){
        $new_hashmap = $hashmap;
        $new_hashmap[$key] = $val;
        return new PharenHashMap($new_hashmap, $this->count+1);
    }

    public function offsetGet($key){
        return $this->hashmap[$key];
    }

    public function offsetSet($key, $val){
    }

    public function offsetUnset($key){
    }

    public function offsetExists($key){
        return isset($this->hashmap[$key]);
    }

    public function count(){
        return $this->count;
    }
}
