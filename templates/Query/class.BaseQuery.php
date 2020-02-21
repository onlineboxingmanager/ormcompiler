<?
#ä
class BaseQuery {

	protected $criterias = array();
	protected $tablename;
	protected $modelname; // name of manager class => example: "PartnerManager"
	protected $table_alias;
	protected $joins = array();
	protected $order;
	protected $groupby;
	protected $having;
	protected $select = array();
	protected $select_custom = array();
	protected $offset;
	protected $limit = 30; // default

	protected $params = array();

	public function setLimit($limit){
		$this->limit = $limit;
	}

	public function setOffset($offset){
		$this->offset = $offset;
	}

	public function setTablename($string){
		$string = strtolower($string);
		$this->table_alias = substr( md5($string), 0, 12);
		$this->tablename = $string;
	}

	public function add($column, $operator = null, $expected_value = null) {
		if(!$column instanceof Criteria)
			$myCriteria = new Criteria($operator, $column, $expected_value);
		else
			$myCriteria = $column;
		$myCriteria->setType(Criteria::LOGICAL_AND);
		$this->criterias[] = $myCriteria;
		//$this->params[] = $expected_value;

		// parameter hinzufügen
		//array_merge($this->params, $criteria->getValue());
		#if( !in_array($operator, array(Criteria::ISNOTNULL, Criteria::ISNULL)) )
			#$this->params[] = $expected_value;
	}

	public function addOr($column, $operator = null, $expected_value = null) {
		if(! $column instanceof Criteria )
			$myCriteria = new Criteria($operator, $column, $expected_value);
		else
			$myCriteria = $column;
		$myCriteria->setType(Criteria::LOGICAL_OR);
		$this->criterias[] = $myCriteria;
	}

	/*
	public function addXOr($column, $operator = null, $expected_value = null) {
		if(! $column instanceof Criteria )
			$myCriteria = new Criteria($operator, $column, $expected_value);
		else
			$myCriteria = $column;
		$myCriteria->setType(Criteria::LOGICAL_XOR);
		$this->criterias[] = $myCriteria;
	}*/

	public function addJoin(BaseQuery $myJoinQuery, $condition, $joinname = ''){
		$myJoinQuery->jointype = $condition;
		$myJoinQuery->joinname = $joinname;
		$this->joins[] = $myJoinQuery;
	}

	public function AddOrder($column, $sorttype = ''){
		$this->order[] = $column.' '.$sorttype;
	}

	public function AddGroupBy($column){
		$this->groupby[] = $column;
	}

	public function AddHaving($string){
		$this->having = $string;
	}

	public function ClearSelects($column){
		$this->select = array();
	}

	public function AddSelect($column){
		$this->select[] = $column;
	}

	public function AddSelectCustom($column, $name){
		$this->select_custom[$name] = $column;
	}

	public function build( $is_count = false ) {
		$models = array();
		$models["main"] = $this->modelname;
		$models["joins"] = array();
		$params = array();

		// spalten von hauptklasse holen
		$select_rows = '';
		Library::requireLibrary(LibraryKeys::ABSTRACTION_DAO_GENERIC($this->modelname));
		$cols = call_user_func(array($this->modelname.'DAO','getColumns'));
		if( is_array($cols) AND !empty($cols) )
			$select_rows = implode(', ', $cols);

		// custom
		foreach( $this->select_custom as $key => $value )
			$select_rows .= ", \n". $value . ' as ' . $key ;

		// JOINS
		$joinsql = '';
		foreach ( $this->joins as $jointable ){
			$jointable instanceof QueryBase;
			//$models["joins"][] = $jointable->modelname;

			$tablename = substr( $jointable->tablename , strpos($jointable->tablename, '.')+ 1);

			// spalten zum select hinzufügen
			Library::requireLibrary(LibraryKeys::ABSTRACTION_DAO_GENERIC($jointable->modelname));
			$cols = call_user_func(array($jointable->modelname.'DAO','getColumns'));

			if( $jointable->joinname AND is_array($cols) AND !empty($cols) )
				foreach ( $cols as $key => $value )
					$cols[$key] = str_replace(' as ', ' as '.strtoupper($jointable->joinname).'_', $value );

			if( is_array($cols) AND !empty($cols) )
				$select_rows .= ", \n".implode(', ',$cols);

			if( $jointable->joinname )
				$select_rows = str_replace( $tablename, $jointable->joinname, $select_rows );

			// join typ
			$joinsql .= "\n".' '. $jointable->jointype. ' ' . $jointable->tablename .' ' . $jointable->joinname . ' ON 1 = 1';

			// bedingungen
			foreach ($jointable->criterias as $key => $criteria){
				$criteria instanceof Criteria;

				$last = (isset($jointable->criterias[$key-1]) ? $jointable->criterias[$key-1] : null );
				$next = (isset($jointable->criterias[$key+1]) ? $jointable->criterias[$key+1] : null );

				switch ($criteria->getType()){
					case Criteria::LOGICAL_AND:
					case Criteria::LOGICAL_OR:
						$joinsql .= ' '.$criteria->getType().' ';
						break;
				}

				if( $next AND $criteria->getType() == Criteria::LOGICAL_AND AND $next->getType() == Criteria::LOGICAL_OR )
					$joinsql .= ' ( ';

				$where = $criteria->getWhereClause();
				if( $jointable->joinname )
					$where = str_replace( $tablename, $jointable->joinname, $where );
				$joinsql .= $where;

				//if( $criteria->getType() == Criteria::LOGICAL_OR AND $last->getType() == Criteria::LOGICAL_AND )
				if( $criteria->getType() == Criteria::LOGICAL_OR AND ( !$next OR $next->getType() != Criteria::LOGICAL_OR ) )
					$joinsql .= ' ) ';

				//$joinsql .= ' '.$criteria->getType().' ';
				//$joinsql .= $criteria->getWhereClause();

				if( $criteria->getValue() == '?' )
					$this->params[] = $criteria->getParam();
			}
		}

		// wenn groupby benutzt wird dürfen nur diese spalten benutzt werden
		//if( !empty($this->groupby) )
			//$select_rows = implode(',', $this->groupby);

		// wenn count gebraucht wird
		if( $is_count )
			$select_rows = 'count(*) cnt';

		$sql = 'SELECT '.$select_rows."\n FROM ".$this->tablename;
		$sql .= $joinsql;

		$sql .= "\n".' WHERE 1 = 1'."\n";
		// where bedingungen

		foreach ( $this->criterias as $key => $criteria ){
			$criteria instanceof Criteria;
			// type

			$last = (isset($this->criterias[$key-1]) ? $this->criterias[$key-1] : null );
			$next = (isset($this->criterias[$key+1]) ? $this->criterias[$key+1] : null );

			switch ($criteria->getType()){
				case Criteria::LOGICAL_AND:
				case Criteria::LOGICAL_OR:
					$sql .= ' '.$criteria->getType().' ';
					break;
			}

			if( $next AND $criteria->getType() == Criteria::LOGICAL_AND AND $next->getType() == Criteria::LOGICAL_OR )
				$sql .= ' ( ';

			$sql .= $criteria->getWhereClause() ;

			//if( $criteria->getType() == Criteria::LOGICAL_OR AND $last->getType() == Criteria::LOGICAL_AND )
			if( $criteria->getType() == Criteria::LOGICAL_OR AND ( !$next OR $next->getType() != Criteria::LOGICAL_OR ) )
				$sql .= ' ) ';

			if( !in_array($criteria->getOperator(), array(Criteria::ISNOTNULL, Criteria::ISNULL)) )
				$this->params[] = $criteria->getParam();
		}

		if ( !empty($this->having) ){
			$sql .= "\n".' HAVING '.$this->having." \n";
		}

		if(!empty($this->groupby))
			$sql .= ' GROUP BY '.implode(' ,',$this->groupby);
		if(!empty($this->order))
			$sql .= ' ORDER BY '.implode(' ,',$this->order);

		#if(isset($_GET['debugmode']) )
			#echo "<pre>$sql</pre>";
		#var_dump($this->params);
		// select verarbeiten und result an klassen übergeben


		// count ausgeben - limit/offset weglassen
		if( $is_count AND ($myResult = BaseDAO::genericQuery($sql, $this->params) ) AND $myResult AND $myResult->next() )
			return $myResult->getInt('cnt');

		// richtig suchen
		$myResult = BaseDAO::genericQuery($sql, $this->params, new SQLLimit($this->limit, $this->offset));
		if( !$myResult )
			return false;

		$classname = $this->modelname.'List';
		$myList = new $classname();
		$get_references_from_database =  !empty($this->joins);
		while($myResult->next()){
			#if(isset($_GET['debugmode']) )
				#var_dump($myResult->getRow());
			$myBaseObject = call_user_func_array(array($this->modelname.'DAO', 'get'.$this->modelname.'FromResult'), array($myResult,$get_references_from_database) );

			foreach ($this->select_custom as $name => $column) {
				$myBaseObject->$name = $myResult->getString($name);
			}

			// subs
			if( !empty($this->joins ) )
			foreach( $this->joins as $join ){
				$classname = $join->modelname;
				$customname = $join->joinname ? strtoupper($join->joinname).'_' : '';
				$mySubObject = call_user_func_array( array($classname.'DAO', 'get'.$classname.'FromResult'), array($myResult, null, $customname) );

				if( $join->joinname )
					$classname = $join->joinname;
				$function = 'set'.$classname;

				if( method_exists($myBaseObject, $function) )
					$myBaseObject->$function($mySubObject);
				else
					$myBaseObject->$classname = $mySubObject;
			}
			$myList->add($myBaseObject);
		}

		return $myList;
	}

	public function count(){
		return $this->build(true);
	}

	public function findOne(){
		$this->setLimit(1);
		$myList = $this->build();
		return $myList->valid() ? $myList->current() : null;
	}

	public function get() {
		return $this->find();
	}

	public function find() {
		return $this->build();
	}

}
