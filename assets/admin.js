( function () {
	'use strict';

	var generateBtn = document.getElementById( 'rsvp-loadgen-generate-btn' );
	var cleanupBtn = document.getElementById( 'rsvp-loadgen-cleanup-btn' );
	var progressWrap = document.getElementById( 'rsvp-loadgen-progress' );
	var progressFill = progressWrap ? progressWrap.querySelector( '.rsvp-loadgen-progress-fill' ) : null;
	var progressLabel = progressWrap ? progressWrap.querySelector( '.rsvp-loadgen-progress-label' ) : null;

	var generateSpinner = document.getElementById( 'rsvp-loadgen-generate-spinner' );
	var generateNoticeEl = document.getElementById( 'rsvp-loadgen-generate-notice' );

	var scenarioTypeSelect = document.getElementById( 'rsvp-loadgen-scenario-type' );
	var scenarioBtn = document.getElementById( 'rsvp-loadgen-scenario-btn' );
	var scenarioSpinner = document.getElementById( 'rsvp-loadgen-scenario-spinner' );
	var scenarioProgress = document.getElementById( 'rsvp-loadgen-scenario-progress' );
	var scenarioProgressFill = scenarioProgress ? scenarioProgress.querySelector( '.rsvp-loadgen-progress-fill' ) : null;
	var scenarioProgressLabel = scenarioProgress ? scenarioProgress.querySelector( '.rsvp-loadgen-progress-label' ) : null;
	var scenarioNoticeEl = document.getElementById( 'rsvp-loadgen-scenario-notice' );
	var scenarioPollTimer = null;

	var runMigrationBtn = document.getElementById( 'rsvp-loadgen-run-migration-btn' );
	var revertMigrationBtn = document.getElementById( 'rsvp-loadgen-revert-migration-btn' );
	var migrationStatusEl = document.getElementById( 'rsvp-loadgen-migration-status' );
	var migrationSpinner = document.getElementById( 'rsvp-loadgen-migration-spinner' );
	var migrationNoticeEl = document.getElementById( 'rsvp-loadgen-migration-notice' );
	var migrationPollTimer = null;

	var scenarioWithVenuesEl = document.getElementById( 'rsvp-loadgen-scenario-with-venues' );
	var scenarioWithOrganizersEl = document.getElementById( 'rsvp-loadgen-scenario-with-organizers' );
	var withVenuesEl = document.getElementById( 'rsvp-loadgen-with-venues' );
	var withOrganizersEl = document.getElementById( 'rsvp-loadgen-with-organizers' );

	var addonNoticeEl = document.getElementById( 'rsvp-loadgen-addon-notice' );

	var addRsvpBtn = document.getElementById( 'rsvp-loadgen-addrsvp-btn' );
	var addRsvpSpinner = document.getElementById( 'rsvp-loadgen-addrsvp-spinner' );
	var addTicketsBtn = document.getElementById( 'rsvp-loadgen-addtickets-btn' );
	var addTicketsSpinner = document.getElementById( 'rsvp-loadgen-addtickets-spinner' );
	var addAttendeesBtn = document.getElementById( 'rsvp-loadgen-addattendees-btn' );
	var addAttendeesSpinner = document.getElementById( 'rsvp-loadgen-addattendees-spinner' );

	// Toggles WP admin's own `.spinner` (built into wp-admin core CSS, no extra asset needed).
	function setSpinner( el, active ) {
		if ( ! el ) {
			return;
		}
		el.classList.toggle( 'is-active', !! active );
	}

	// Shows/hides one of the notice boxes (styled like WP admin notices) with a success/error/
	// info/warning message. Passing an empty message hides it, so it doesn't linger stale into
	// the next run.
	function setNotice( el, type, message ) {
		if ( ! el ) {
			return;
		}
		if ( ! message ) {
			el.hidden = true;
			return;
		}
		el.className = 'notice inline rsvp-loadgen-notice notice-' + type;
		var p = el.querySelector( 'p' );
		if ( p ) {
			p.textContent = message;
		}
		el.hidden = false;
	}

	function postAjax( action, extraParams ) {
		var body = new URLSearchParams( Object.assign( {
			action: action,
			nonce: RSVPLoadgen.nonce,
		}, extraParams || {} ) );

		return fetch( RSVPLoadgen.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function showProgress( done, total, label ) {
		if ( ! progressWrap ) {
			return;
		}
		progressWrap.hidden = false;
		var pct = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
		if ( progressFill ) {
			progressFill.style.width = pct + '%';
		}
		if ( progressLabel ) {
			progressLabel.textContent = label;
		}
	}

	function refreshCounts() {
		postAjax( RSVPLoadgen.actions.status ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				return;
			}
			Object.keys( res.data ).forEach( function ( kind ) {
				var cell = document.querySelector( '#rsvp-loadgen-counts td[data-kind="' + kind + '"]' );
				if ( cell ) {
					cell.textContent = res.data[ kind ];
				}
			} );
		} );
	}

	function runGenerateLoop( total, minAttendees, maxAttendees, withVenues, withOrganizers ) {
		var runId = null;

		function step() {
			return postAjax( RSVPLoadgen.actions.generate, {
				total: total,
				min_attendees: minAttendees,
				max_attendees: maxAttendees,
				with_venues: withVenues,
				with_organizers: withOrganizers,
				chunk_size: RSVPLoadgen.chunkSize,
				run_id: runId || '',
			} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					showProgress( 0, total, '' );
					setNotice( generateNoticeEl, 'error', 'Error generating data. See console/logs for details.' );
					return;
				}

				runId = res.data.run_id;
				showProgress( res.data.done, res.data.total, res.data.done + ' / ' + res.data.total + ' generated' );

				if ( ! res.data.finished ) {
					return step();
				}

				setNotice( generateNoticeEl, 'success', 'Generated ' + res.data.total + ' tickets (run: ' + runId + ').' );
			} );
		}

		return step().then( refreshCounts );
	}

	function showScenarioProgress( done, total, label ) {
		if ( ! scenarioProgress ) {
			return;
		}
		scenarioProgress.hidden = false;
		var pct = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
		if ( scenarioProgressFill ) {
			scenarioProgressFill.style.width = pct + '%';
		}
		if ( scenarioProgressLabel ) {
			scenarioProgressLabel.textContent = label;
		}
	}

	// A scenario runs as a background Action Scheduler job (see Scenario_Job), not a
	// client-driven chunk loop — the browser only has to poll status, so the tab can be closed
	// mid-run and generation continues server-side. This also means, on page load, a mid-run or
	// just-finished job needs to be reflected immediately (see the boot call at the bottom).
	function scenarioResultMessage( job ) {
		return 'Scenario "' + job.type + '" complete: ' + job.total + ' units, ' +
			job.ticket_total + ' RSVP tickets, ' + job.attendee_total + ' attendees, ' +
			job.orphaned + ' orphaned (target ' + job.orphan_rate + '%). Run: ' + job.run_id;
	}

	function applyScenarioJobState( job ) {
		if ( ! job || 'idle' === job.status ) {
			setSpinner( scenarioSpinner, false );
			return;
		}

		if ( 'running' === job.status ) {
			if ( scenarioBtn ) {
				scenarioBtn.disabled = true;
			}
			if ( scenarioTypeSelect ) {
				scenarioTypeSelect.disabled = true;
			}
			setSpinner( scenarioSpinner, true );
			showScenarioProgress( job.done, job.total, job.done + ' / ' + job.total + ' units generated (scenario: ' + job.type + ')' );
			return;
		}

		setSpinner( scenarioSpinner, false );
		if ( scenarioBtn ) {
			scenarioBtn.disabled = false;
		}
		if ( scenarioTypeSelect ) {
			scenarioTypeSelect.disabled = false;
		}

		if ( 'completed' === job.status ) {
			showScenarioProgress( job.done, job.total, job.done + ' / ' + job.total + ' units generated' );
			setNotice( scenarioNoticeEl, 'success', scenarioResultMessage( job ) );
		} else if ( 'failed' === job.status ) {
			setNotice( scenarioNoticeEl, 'error', 'Scenario failed: ' + ( job.error || 'unknown error' ) );
		}
	}

	function pollScenarioStatus() {
		return postAjax( RSVPLoadgen.actions.scenarioStatus ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				return null;
			}
			applyScenarioJobState( res.data );
			return res.data;
		} );
	}

	function startScenarioPolling() {
		if ( scenarioPollTimer ) {
			clearInterval( scenarioPollTimer );
		}

		scenarioPollTimer = setInterval( function () {
			pollScenarioStatus().then( function ( data ) {
				if ( ! data || 'running' !== data.status ) {
					clearInterval( scenarioPollTimer );
					scenarioPollTimer = null;
					refreshCounts();
				}
			} );
		}, 3000 );
	}

	if ( scenarioBtn ) {
		scenarioBtn.addEventListener( 'click', function () {
			var type = scenarioTypeSelect ? scenarioTypeSelect.value : 'usual';

			scenarioBtn.disabled = true;
			if ( scenarioTypeSelect ) {
				scenarioTypeSelect.disabled = true;
			}
			setSpinner( scenarioSpinner, true );
			setNotice( scenarioNoticeEl, 'info', '' );

			postAjax( RSVPLoadgen.actions.scheduleScenario, {
				type: type,
				with_venues: scenarioWithVenuesEl && scenarioWithVenuesEl.checked ? 1 : 0,
				with_organizers: scenarioWithOrganizersEl && scenarioWithOrganizersEl.checked ? 1 : 0,
			} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					var message = ( res && res.data && res.data.message ) || 'Error starting scenario. See console/logs for details.';
					setSpinner( scenarioSpinner, false );
					scenarioBtn.disabled = false;
					if ( scenarioTypeSelect ) {
						scenarioTypeSelect.disabled = false;
					}
					setNotice( scenarioNoticeEl, 'error', message );
					return;
				}

				applyScenarioJobState( res.data );
				startScenarioPolling();
			} );
		} );
	}

	// Boot-time resume: if a scenario job is already running (tab was closed/reopened, or
	// another admin started one), reflect it immediately and keep polling.
	pollScenarioStatus().then( function ( data ) {
		if ( data && 'running' === data.status ) {
			startScenarioPolling();
		}
	} );

	function runCleanupLoop() {
		function step() {
			return postAjax( RSVPLoadgen.actions.cleanup, {
				chunk_size: RSVPLoadgen.chunkSize,
			} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					showProgress( 0, 0, '' );
					setNotice( generateNoticeEl, 'error', 'Error cleaning up data. See console/logs for details.' );
					return;
				}

				var remaining = res.data.remaining;
				showProgress( 0, 0, remaining + ' remaining' );

				if ( ! res.data.finished ) {
					return step();
				}

				setNotice( generateNoticeEl, 'success', 'Cleanup complete. Nothing left to remove.' );
			} );
		}

		return step().then( refreshCounts );
	}

	if ( generateBtn ) {
		generateBtn.addEventListener( 'click', function () {
			var total = parseInt( document.getElementById( 'rsvp-loadgen-total' ).value, 10 ) || 5000;
			var minAttendees = parseInt( document.getElementById( 'rsvp-loadgen-min-attendees' ).value, 10 ) || 1;
			var maxAttendees = parseInt( document.getElementById( 'rsvp-loadgen-max-attendees' ).value, 10 ) || 20;
			var withVenues = withVenuesEl && withVenuesEl.checked ? 1 : 0;
			var withOrganizers = withOrganizersEl && withOrganizersEl.checked ? 1 : 0;

			generateBtn.disabled = true;
			cleanupBtn.disabled = true;
			if ( scenarioBtn ) {
				scenarioBtn.disabled = true;
			}
			setSpinner( generateSpinner, true );
			setNotice( generateNoticeEl, 'info', '' );

			runGenerateLoop( total, minAttendees, maxAttendees, withVenues, withOrganizers ).finally( function () {
				generateBtn.disabled = false;
				cleanupBtn.disabled = false;
				if ( scenarioBtn ) {
					scenarioBtn.disabled = false;
				}
				setSpinner( generateSpinner, false );
			} );
		} );
	}

	if ( cleanupBtn ) {
		cleanupBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( 'Delete all data generated by this tool? This cannot be undone.' ) ) {
				return;
			}

			generateBtn.disabled = true;
			cleanupBtn.disabled = true;
			if ( scenarioBtn ) {
				scenarioBtn.disabled = true;
			}
			setSpinner( generateSpinner, true );
			setNotice( generateNoticeEl, 'info', '' );

			runCleanupLoop().finally( function () {
				generateBtn.disabled = false;
				cleanupBtn.disabled = false;
				if ( scenarioBtn ) {
					scenarioBtn.disabled = false;
				}
				setSpinner( generateSpinner, false );
			} );
		} );
	}

	function setMigrationButtons( canRun, canRevert ) {
		if ( runMigrationBtn ) {
			runMigrationBtn.disabled = ! canRun;
		}
		if ( revertMigrationBtn ) {
			revertMigrationBtn.disabled = ! canRevert;
		}
	}

	function refreshMigrationStatus() {
		return postAjax( RSVPLoadgen.actions.migrationStatus ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				return;
			}

			if ( migrationStatusEl ) {
				migrationStatusEl.textContent = res.data.label;
			}

			setMigrationButtons( res.data.can_run, res.data.can_revert );

			return res.data;
		} );
	}

	// Stops polling and gives a clear "done" signal: a success notice once the migration has
	// actually settled (buttons reflect a runnable/revertible state again), or a warning if we
	// gave up after ~5 minutes without seeing that (it may still finish later in the background).
	function stopMigrationPolling( settled, data ) {
		if ( migrationPollTimer ) {
			clearInterval( migrationPollTimer );
			migrationPollTimer = null;
		}

		setSpinner( migrationSpinner, false );

		if ( settled ) {
			setNotice( migrationNoticeEl, 'success', 'Migration finished. Status: ' + ( ( data && data.label ) || '' ) );
		} else {
			setNotice( migrationNoticeEl, 'warning', 'Still running in the background after 5 minutes — check back later, or refresh this page.' );
		}
	}

	// Polls migration status every 3s (Shepherd/Action Scheduler processes batches
	// in the background), stopping once the buttons reflect a settled state or
	// after ~5 minutes, whichever comes first.
	function startMigrationPolling() {
		if ( migrationPollTimer ) {
			clearInterval( migrationPollTimer );
		}

		setSpinner( migrationSpinner, true );

		var elapsed = 0;
		var maxMs = 5 * 60 * 1000;

		migrationPollTimer = setInterval( function () {
			elapsed += 3000;

			refreshMigrationStatus().then( function ( data ) {
				if ( ! data ) {
					return;
				}

				if ( data.can_run || data.can_revert ) {
					stopMigrationPolling( true, data );
					return;
				}

				if ( elapsed >= maxMs ) {
					stopMigrationPolling( false, data );
				}
			} );
		}, 3000 );
	}

	function runMigrationAction( action, confirmMessage ) {
		if ( confirmMessage && ! window.confirm( confirmMessage ) ) {
			return;
		}

		setMigrationButtons( false, false );
		setSpinner( migrationSpinner, true );
		setNotice( migrationNoticeEl, 'info', '' );

		postAjax( action ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				var message = ( res && res.data && res.data.message ) || 'Error scheduling migration. See console/logs for details.';
				setSpinner( migrationSpinner, false );
				setNotice( migrationNoticeEl, 'error', message );
				refreshMigrationStatus();
				return;
			}

			setNotice( migrationNoticeEl, 'info', ( res.data && res.data.message ) || 'Migration scheduled, running in the background...' );
			startMigrationPolling();
		} );
	}

	if ( runMigrationBtn ) {
		runMigrationBtn.addEventListener( 'click', function () {
			runMigrationAction( RSVPLoadgen.actions.runMigration, null );
		} );
	}

	if ( revertMigrationBtn ) {
		revertMigrationBtn.addEventListener( 'click', function () {
			runMigrationAction(
				RSVPLoadgen.actions.revertMigration,
				'Revert the rsvp-to-tc migration back to legacy V1 RSVP data?'
			);
		} );
	}

	function runAddonAction( button, spinner, action, params, successMessage ) {
		button.disabled = true;
		setSpinner( spinner, true );
		setNotice( addonNoticeEl, 'info', '' );

		postAjax( action, params ).then( function ( res ) {
			button.disabled = false;
			setSpinner( spinner, false );

			if ( ! res || ! res.success ) {
				var message = ( res && res.data && res.data.message ) || 'Error. See console/logs for details.';
				setNotice( addonNoticeEl, 'error', message );
				return;
			}

			setNotice( addonNoticeEl, 'success', successMessage( res.data ) );
			refreshCounts();
		} );
	}

	if ( addRsvpBtn ) {
		addRsvpBtn.addEventListener( 'click', function () {
			var eventId = parseInt( document.getElementById( 'rsvp-loadgen-addrsvp-event-id' ).value, 10 ) || 0;
			var quantity = parseInt( document.getElementById( 'rsvp-loadgen-addrsvp-quantity' ).value, 10 ) || 1;

			runAddonAction( addRsvpBtn, addRsvpSpinner, RSVPLoadgen.actions.addRsvp, { event_id: eventId, quantity: quantity }, function ( data ) {
				return 'Added ' + data.created + ' RSVP ticket(s) to event ' + eventId + ' (run: ' + data.run_id + ').';
			} );
		} );
	}

	if ( addTicketsBtn ) {
		addTicketsBtn.addEventListener( 'click', function () {
			var eventId = parseInt( document.getElementById( 'rsvp-loadgen-addtickets-event-id' ).value, 10 ) || 0;
			var quantity = parseInt( document.getElementById( 'rsvp-loadgen-addtickets-quantity' ).value, 10 ) || 1;

			runAddonAction( addTicketsBtn, addTicketsSpinner, RSVPLoadgen.actions.addTickets, { event_id: eventId, quantity: quantity }, function ( data ) {
				return 'Added ' + data.created + ' paid ticket(s) to event ' + eventId + ' (run: ' + data.run_id + ').';
			} );
		} );
	}

	if ( addAttendeesBtn ) {
		addAttendeesBtn.addEventListener( 'click', function () {
			var ticketId = parseInt( document.getElementById( 'rsvp-loadgen-addattendees-ticket-id' ).value, 10 ) || 0;
			var quantity = parseInt( document.getElementById( 'rsvp-loadgen-addattendees-quantity' ).value, 10 ) || 1;

			runAddonAction( addAttendeesBtn, addAttendeesSpinner, RSVPLoadgen.actions.addAttendees, { ticket_id: ticketId, quantity: quantity }, function ( data ) {
				return 'Added ' + data.created + ' attendee(s) to ticket ' + ticketId + ' (run: ' + data.run_id + ').';
			} );
		} );
	}
} )();
