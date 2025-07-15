<?php

namespace ZentryGate;

class UserPage
{
	private array $sessionData;


	public function __construct (array $sessionData)
	{
		$this->sessionData = $sessionData;
	}


	public function render ()
	{
		// Render the user-specific page content
		echo '<h2>Welcome to the User Page</h2>';
		echo '<p>This is the content for regular users.</p>';

		// You can add more user-specific content here
	}


	private function renderUserMenu ()
	{
		// Render the user menu if needed
		echo '<nav class="user-menu">';
		echo '<ul>';
		echo '<li><a href="#">Profile</a></li>';
		echo '<li><a href="#">Settings</a></li>';
		echo '<li><a href="#">Logout</a></li>';
		echo '</ul>';
		echo '</nav>';
	}
}