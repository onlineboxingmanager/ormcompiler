<?
#ä
class Criteria {

	const EQUAL = '=';
	const NOT_EQUAL = '<>';
	const ALT_NOT_EQUAL = '!=';
	const GREATER_THAN = '>';
	const LESS_THAN = '<';
	const GREATER_EQUAL = '>=';
	const LESS_EQUAL = '<=';
	const LIKE = 'LIKE';
	const NOT_LIKE = 'NOT LIKE';
	const CUSTOM = 'CUSTOM';
	const DISTINCT = 'DISTINCT';
	const IN = 'IN';
	const NOT_IN = 'NOT IN';
	const ALL = 'ALL';
	const JOIN = 'JOIN';
	const ASC = 'ASC';
	const DESC = 'DESC';
	const ISNULL = ' IS NULL';
	const ISNOTNULL = 'IS NOT NULL';
	const CURRENT_DATE = 'CURRENT_DATE';
	const CURRENT_TIME = 'CURRENT_TIME';
	const CURRENT_TIMESTAMP = 'CURRENT_TIMESTAMP';
	const JOIN_LEFT = 'LEFT JOIN';
	const JOIN_RIGHT = 'RIGHT JOIN';
	const JOIN_INNER = 'INNER JOIN';
	const LOGICAL_OR = 'OR';
	const LOGICAL_AND = 'AND';

	private $operator = null, $field = null, $value = null, $param;
	private $table;
	private $joins = array();
	private $type;
	private $stringchar = '"';

	public function __construct($operator,$column,$value) {
		$this->operator = $operator;
		// set table and column
		$dotPos = strrpos($column, '.');
		if ($dotPos === false) {
			// no dot => aliased column
			$this->table = null;
			$this->column = $column;
		} else {
			$this->table = substr($column, 0, $dotPos);
			$this->column = substr($column, $dotPos + 1);
		}
		$this->field = $column;

		// value hinzufügen wenn nicht FK verknüpfung
		$this->value = $value;
		if( !preg_match('/^tbl_[a-z0-9\_]+\./i', $value) AND !in_array($this->operator, array(Criteria::ISNOTNULL, Criteria::ISNULL)) )
		{
			$this->value = '?';
			$this->param = $value;
		}
	}

	public function setType($type){
		$this->type = $type;
	}

	public function getType(){
		return $this->type;
	}

	public function getOperator(){
		return $this->operator;
	}

	public function getStringChar(){
		return $this->stringchar;
	}

	public function getParam(){
		return $this->param;
	}

	public function getValue(){
		return $this->value;
	}

	public function getWhereClause() {
		switch ( $this->operator ){
			case Criteria::ISNOTNULL:
			case Criteria::ISNULL:
				return implode(" ",array($this->field,$this->operator));
				break;
			default:
				return implode(" ",array($this->field,$this->operator,$this->value));
		}
	}

}