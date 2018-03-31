<?php
	class login_attempt
	{
		// the interval between any two login attempts
		$attempt_delay = 1000;

		// the interval after which an unchecked attempt is considered dead
		$attempt_expiration_timeout = 5000;

		// number of queued attempts per user (IP)
		$max_queued_attempts_per_user = 5;

		// max queued attempts overall
		$max_queued_attempts_overall = 30;

		// id to be assigned to a particular attemped and saved in the DB
		private $attempt_id;

		// username to be valdiated wrt the database content
		private $username;

		// password to be validated wrt thebase content
		private $password;

		// boolean variable that'll store if the login is valid
		private $isLoginValid;

		// pdo variable
		// private $pdo;

		// the statement used to check whether the attemp is ready to be processed
		private $readyCheckStatement;

		// the statement used to update the attempt entry in the database on each isReady call
		private $checkUpdateStatement;

		// creates a login attempt and queues it
		public function __construct($username, $password)
		{
			$this->username = $username;
			$this->password = $password;

			if(!$this->isQueueSizeExceeded())
				$this->addToQueue();
			else
				throw new Exception("Queue full.",503);
		}
	}

	// function that updates the database with the attempt, this function also fetches the id generated and assigns it to the class's id data member
	private function addToQueue()
	{
		$connection = mysqli_conect('localhost','root');
		if(!$connection)
			echo "Connection to database server failed";
		else
		{
			mysqli_select_db($connection,'prevent_brute_force');
			$query = "insert into login_attempt_queue(ip_address, username) values ($_SERVER['REMOTE_ADDR'],'$this->username')";

			$query_result = mysqli_query($conection, $query) or die(mysqli_error($connection)); // dies when ip address already exists

			$query = "select id from login_attempt_queue where ip_address=$_SERVER['REMOTE_ADDR']";
			$query_result = mysqli_query($connection,$query) or die(mysqli_error($connection));

			$data = mysqli_fetch_array($query_result, MYSQLI_ASSOC);
			$this->attempt_id = $data['id'];

			mysqli_close($connection);
		}
	}

	// returns boolean true if the max number of attempts per user and the max attempts overall have not been exceeded
	private function isQueueSizeExceeded()
	{
		$connection = mysqli_connect("localhost","root");
		if(!$connection)
			echo "Connection to the database server failed!";
		else
		{
			mysqli_select_db($connection,'prevent_brute_force');
			$query = "select count(*) as overall, count(if(username=$this->username,true,NULL)) as user from login_attempt_queue where last_checked > NOW() - INTERVAL $attempt_expiration_timeout*1000 MICROSECOND";
			$query_result = mysqli_query($connection,$query);

			$row = mysqli_fetch_array($query_result,MYSQLI_ASSOC);
			if($row['overall'] == 0 || $row['user'] == 0)
			{
				echo "Failed to query queue size";
				exit();
			}
			mysqli_close($connection);
			return($row['overall'] >= $max_queued_attempts_overall || $row['user'] >= $max_queued_attempts_per_user);
		}
	}

	// checks if there is some id in the database with last_checked>=attempt_expiration_timeout
	private function isReady()
	{
		// check if there is an id not expired for the attempting username
		if(!$this->readyCheckStatement)
		{
			$query = "select id from login_attempt_queue where last_checked > now() - interval $attempt_expiration_timeout*1000 MICROSECOND and username = '$this->username' order by id ASC limit 1"; // returns latest checked in record

			$connection = mysqli_connect("localhost","root");
			mysqli_select_db($connection,'prevent_brute_force');

			$query_result = mysqli_query($connection,$query);
			$this->readyCheckStatement = true;
			$row = mysqli_fetch_array($query_result,MYSQLI_ASSOC);
			$res = $row['id']; 
			// mysqli_close($connection);
		}

		if(!$this->checkUpdateStatement)
		{
			// $connection = mysqli_connect("localhost","root");
			// mysqli_select_db($connection,'prevent_brute_force');

			$query = "update login_attempt_queue set last_checked = CURRENT_TIMESTAMP WHERE id = $this->attempt_id limit 1"; // if user with same attemp id attempts again, then update last_checked to present timestamp
			$query_result = mysqli_query($connection,$query);
			$this->checkUpdateStatement = true;

			mysqli_close($connection);

			return $res === $this->attempt_id; 

		}
	}

	public function isValid()
	{
		if($this->isLoginValid === null)
		{
			$connection = mysqli_connect('localhost','root');
			mysqli_select_db($connection,'prevent_brute_force'); // users database

			$query = "select password from users where username = '$this->username'";
			$query_result = mysqli_query($connection,$query);
			$res = mysqli_fetch_array($query_result,MYSQLI_ASSOC);

			$pwd = $res['password'];

			if($pwd)
				$this->isLoginValid = ($pwd === $this->password);
			else
				$this->isLoginValid = false;

			usleep($attempt_delay*1000); // enforcing delay between two login attempts

			// removing the login attempt as it has been processed
			$query = 'delete from login_attempt_queue where id = $this->attempt_id or last_checked < now() - interval $attempt_expiration_timeout*1000';
			$query_result = mysqli_query($connection,$query);
		}

		return $this->isLoginValid;
	}

	public function whenReady($callback, $check_timer = 250)
	{
		while(!$this->isReady())
			usleeep($check_timer*1000); // usleep takes time in microseconds
		
		if(is_callable($callback))
			call_user_func($callback,$this->isValid()); // call_user_func calls the $callback function passing its remaining parameters as arguments to $callback
	}
?>