#!/usr/bin/env node
"use strict";
/**
 * Manages a Parsoid server for testing.
 */

var child_process = require( 'child_process' ),
	path = require( 'path' );

var forkedServer;

/**
 * Starts a Parsoid server on passed port or a random port if none passed.
 * The callback will get the URL of the started server.
 */
var startParsoidServer = function ( cb, port ) {
	if ( !port ) {
		port = 9000 + Math.floor( Math.random() * 100 );
	}

	var serverURL = 'http://localhost:' + port.toString() + '/';
	console.log( "Starting Parsoid server at", serverURL );
	forkedServer = child_process.fork( __dirname + '/../api/server.js',
		[ '--num-workers', '1',
			'--config', path.resolve( __dirname, './test.localsettings.js' ) ],
		{ env:
			{
				PORT: port,
				INTERFACE: "localhost",
				NODE_PATH: process.env.NODE_PATH
			}
		} );

	// If this process dies, kill our Parsoid server
	var weDied = function () {
		forkedServer.removeListener( 'exit', startParsoidServer );
		forkedServer.kill();
	};
	process.on( 'exit', weDied );

	// If it dies on its own, restart it
	forkedServer.on( 'exit', function( ) {
		process.removeListener( 'exit', weDied );
		// Don't pass a port, so it changes in case the problem was an already
		// occupied one
		startParsoidServer();
	} );

	// Wait 1 second to make sure it has had time to start
	setTimeout( cb, 1000, serverURL );
};

var stopParsoidServer = function () {
	if ( forkedServer ) {
		forkedServer.removeListener( 'exit', startParsoidServer );
		forkedServer.kill();
	}
};

module.exports = {
	startParsoidServer: startParsoidServer,
	stopParsoidServer: stopParsoidServer
};
