<?php	
	class login_attempt
	{

		// const 1000 = 1000;


		// const 5000 = 5000;


		// const 5 = 5;

		// const 30 = 30;

		private $attempt_id;

		private $username;

		private $password;

		private $isLoginValid;

		private $readyCheckStatement;

		private $checkUpdateStatement;

		public function __construct($username, $password)
		{
			$this->username = $username;
			$this->password = $password;

			if(!$this->isQueueSizeExceeded())
				$this->addToQueue();
			else
				throw new Exception("Queue full.",503);
		}

		private function addToQueue()
		{
			$connection = mysqli_connect('localhost','root',"pITAJI*143");
			if(!$connection)
				echo "Connection to database server failed";
			else
			{
				mysqli_select_db($connection,'prevent_brute_force');
				$uname = $this->username;
				$ip_addr = $_SERVER['REMOTE_ADDR'];
				$query = "insert into login_attempt_queue(ip_address, username) values ('$ip_addr','$this->username')";

				$query_result = mysqli_query($connection, $query) or die(mysqli_error($connection));

				$query = "select id from login_attempt_queue where ip_address='$ip_addr'";
				$query_result = mysqli_query($connection,$query) or die(mysqli_error($connection));

				$data = mysqli_fetch_assoc($query_result);
				$this->attempt_id = $data['id'];
				// echo "$this->attempt_id";

				mysqli_close($connection);
			}
		}


		private function isQueueSizeExceeded()
		{
			$connection = mysqli_connect("localhost","root","pITAJI*143");
			if(!$connection)
				echo "Connection to the database server failed!";
			else
			{
				mysqli_select_db($connection,'prevent_brute_force');
				$query = "select count(*) as overall, count(if(username='$this->username',true,NULL)) as user from login_attempt_queue where last_checked > NOW() - INTERVAL 5000*1000 MICROSECOND";
				$query_result = mysqli_query($connection,$query);

				if($query_result != false)
				{
					$row = mysqli_fetch_assoc($query_result) or die(mysqli_error($connection));
					if(!$row)
					{
						echo "Failed to query queue size.";
						exit();
					}
					// if($row['overall'] == 0 || $row['user'] == 0)
					// {
					// 	echo "2.Failed to query queue size";
					// 	exit();
					// }
					mysqli_close($connection);
					return $row['overall'] >= 30 || $row['user'] >= 5;
				}
			}
		}

		private function isReady()
		{
			if(!$this->readyCheckStatement)
			{
				$query = "select id from login_attempt_queue where last_checked > now() - interval 5000*1000 MICROSECOND and username = '$this->username' order by id ASC limit 1"; 
				$connection = mysqli_connect("localhost","root","pITAJI*143");
				mysqli_select_db($connection,'prevent_brute_force');

				$query_result = mysqli_query($connection,$query);
				$this->readyCheckStatement = true;
				$row = mysqli_fetch_assoc($query_result);
				$res = $row['id']; 
			}

			if(!$this->checkUpdateStatement)
			{
				$query = "update login_attempt_queue set last_checked = CURRENT_TIMESTAMP WHERE id = $this->attempt_id limit 1";
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
				$connection = mysqli_connect('localhost','root','pITAJI*143');
				mysqli_select_db($connection,'prevent_brute_force');

				$query = "select password from users where username = '$this->username'";
				$query_result = mysqli_query($connection,$query);
				$res = mysqli_fetch_assoc($query_result);

				$pwd = $res['password'];

				if($pwd)
					$this->isLoginValid = ($pwd === $this->password);
				else
				{
					$this->isLoginValid = false;
					usleep(1000*1000);
				}

				// usleep(1000*1000);
				// echo "$this->attempt_id";
				$query = "delete from login_attempt_queue where id = $this->attempt_id and last_checked > now() - interval 5000*1000 MICROSECOND";
				$query_result = mysqli_query($connection,$query);
				// if($query_result)
					// echo "haha: $query_result";
			}

			mysqli_close($connection);
				
			return $this->isLoginValid;
		}

		public function whenReady($callback, $check_timer = 250)
		{
			while(!$this->isReady())
				usleeep($check_timer*1000);
			
			if(is_callable($callback))
				call_user_func($callback,$this->isValid());
		}
	}

	if((!empty($_POST["username"])) && (!empty($_POST["password"])))
	{
		// echo "checking";
		try
		{
			$attempt = new login_attempt($_POST['username'],$_POST['password']);
			$attempt->whenReady(function($success) {
					echo $success?"Valid":"Invalid";
				});
		}
		catch(Exception $e)
		{
			if($e->getCode() == 503)
			{
				header('HTTP/1.1 503 Service Unavailable');
			}
		}
	}
	else
		echo "Missing user input";
?>