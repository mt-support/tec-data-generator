( function () {
	'use strict';

	var generateBtn = document.getElementById( 'tec-data-generator-generate-btn' );
	var cleanupBtn = document.getElementById( 'tec-data-generator-cleanup-btn' );
	var progressWrap = document.getElementById( 'tec-data-generator-progress' );
	var progressFill = progressWrap ? progressWrap.querySelector( '.tec-data-generator-progress-fill' ) : null;
	var progressLabel = progressWrap ? progressWrap.querySelector( '.tec-data-generator-progress-label' ) : null;

	var generateSpinner = document.getElementById( 'tec-data-generator-generate-spinner' );
	var generateNoticeEl = document.getElementById( 'tec-data-generator-generate-notice' );

	// Split sections: Generate Events (containers only) and Generate Tickets (with attendees).
	var eventsBtn = document.getElementById( 'tec-data-generator-events-generate-btn' );
	var eventsSpinner = document.getElementById( 'tec-data-generator-events-spinner' );
	var eventsProgress = document.getElementById( 'tec-data-generator-events-progress' );
	var eventsProgressFill = eventsProgress ? eventsProgress.querySelector( '.tec-data-generator-progress-fill' ) : null;
	var eventsProgressLabel = eventsProgress ? eventsProgress.querySelector( '.tec-data-generator-progress-label' ) : null;
	var eventsNoticeEl = document.getElementById( 'tec-data-generator-events-notice' );

	var ticketsBtn = document.getElementById( 'tec-data-generator-tickets-generate-btn' );
	var ticketsSpinner = document.getElementById( 'tec-data-generator-tickets-spinner' );
	var ticketsProgress = document.getElementById( 'tec-data-generator-tickets-progress' );
	var ticketsProgressFill = ticketsProgress ? ticketsProgress.querySelector( '.tec-data-generator-progress-fill' ) : null;
	var ticketsProgressLabel = ticketsProgress ? ticketsProgress.querySelector( '.tec-data-generator-progress-label' ) : null;
	var ticketsNoticeEl = document.getElementById( 'tec-data-generator-tickets-notice' );

	var seriesBtn = document.getElementById( 'tec-data-generator-series-generate-btn' );
	var seriesSpinner = document.getElementById( 'tec-data-generator-series-spinner' );
	var seriesProgress = document.getElementById( 'tec-data-generator-series-progress' );
	var seriesProgressFill = seriesProgress ? seriesProgress.querySelector( '.tec-data-generator-progress-fill' ) : null;
	var seriesProgressLabel = seriesProgress ? seriesProgress.querySelector( '.tec-data-generator-progress-label' ) : null;
	var seriesNoticeEl = document.getElementById( 'tec-data-generator-series-notice' );

	var scenarioTypeSelect = document.getElementById( 'tec-data-generator-scenario-type' );
	var scenarioBtn = document.getElementById( 'tec-data-generator-scenario-btn' );
	var scenarioCancelBtn = document.getElementById( 'tec-data-generator-scenario-cancel-btn' );
	var scenarioSpinner = document.getElementById( 'tec-data-generator-scenario-spinner' );
	var scenarioProgress = document.getElementById( 'tec-data-generator-scenario-progress' );
	var scenarioProgressFill = scenarioProgress ? scenarioProgress.querySelector( '.tec-data-generator-progress-fill' ) : null;
	var scenarioProgressLabel = scenarioProgress ? scenarioProgress.querySelector( '.tec-data-generator-progress-label' ) : null;
	var scenarioNoticeEl = document.getElementById( 'tec-data-generator-scenario-notice' );
	var scenarioPollTimer = null;

	var runMigrationBtn = document.getElementById( 'tec-data-generator-run-migration-btn' );
	var revertMigrationBtn = document.getElementById( 'tec-data-generator-revert-migration-btn' );
	var migrationStatusEl = document.getElementById( 'tec-data-generator-migration-status' );
	var migrationSpinner = document.getElementById( 'tec-data-generator-migration-spinner' );
	var migrationNoticeEl = document.getElementById( 'tec-data-generator-migration-notice' );
	var migrationPollTimer = null;

	var scenarioWithVenuesEl = document.getElementById( 'tec-data-generator-scenario-with-venues' );
	var scenarioWithOrganizersEl = document.getElementById( 'tec-data-generator-scenario-with-organizers' );
	var withVenuesEl = document.getElementById( 'tec-data-generator-with-venues' );
	var withOrganizersEl = document.getElementById( 'tec-data-generator-with-organizers' );

	var addonNoticeEl = document.getElementById( 'tec-data-generator-addon-notice' );

	var addRsvpBtn = document.getElementById( 'tec-data-generator-addrsvp-btn' );
	var addRsvpSpinner = document.getElementById( 'tec-data-generator-addrsvp-spinner' );
	var addTicketsBtn = document.getElementById( 'tec-data-generator-addtickets-btn' );
	var addTicketsSpinner = document.getElementById( 'tec-data-generator-addtickets-spinner' );
	var addAttendeesBtn = document.getElementById( 'tec-data-generator-addattendees-btn' );
	var addAttendeesSpinner = document.getElementById( 'tec-data-generator-addattendees-spinner' );

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
		el.className = 'notice inline tec-data-generator-notice notice-' + type;
		var p = el.querySelector( 'p' );
		if ( p ) {
			p.textContent = message;
		}
		el.hidden = false;
	}

	function postAjax( action, extraParams ) {
		var body = new URLSearchParams( Object.assign( {
			action: action,
			nonce: TecDataGenerator.nonce,
		}, extraParams || {} ) );

		return fetch( TecDataGenerator.ajaxUrl, {
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
		postAjax( TecDataGenerator.actions.status ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				return;
			}
			Object.keys( res.data ).forEach( function ( kind ) {
				var cell = document.querySelector( '#tec-data-generator-counts td[data-kind="' + kind + '"]' );
				if ( cell ) {
					cell.textContent = res.data[ kind ];
				}
			} );
		} );
	}

	function runGenerateLoop( total, minAttendees, maxAttendees, withVenues, withOrganizers, eventTypes, ticketType ) {
		var runId = null;

		function step() {
			return postAjax( TecDataGenerator.actions.generate, {
				total: total,
				min_attendees: minAttendees,
				max_attendees: maxAttendees,
				with_venues: withVenues,
				with_organizers: withOrganizers,
				event_types: ( eventTypes && eventTypes.length ? eventTypes : [ 'single' ] ).join( ',' ),
				ticket_type: ticketType || 'rsvp',
				chunk_size: TecDataGenerator.chunkSize,
				run_id: runId || '',
			} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					var message = ( res && res.data && res.data.message ) || 'Error generating data. See console/logs for details.';
					showProgress( 0, total, '' );
					setNotice( generateNoticeEl, 'error', message );
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

	function showSectionProgress( wrap, fill, label, done, total, text ) {
		if ( ! wrap ) {
			return;
		}
		wrap.hidden = false;
		var pct = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
		if ( fill ) {
			fill.style.width = pct + '%';
		}
		if ( label ) {
			label.textContent = text;
		}
	}

	function runEventsLoop( total, container, editor, withVenues, withOrganizers, eventTypes ) {
		var runId = null;

		function step() {
			return postAjax( TecDataGenerator.actions.generateEvents, {
				total: total,
				container: container,
				editor: editor,
				with_venues: withVenues,
				with_organizers: withOrganizers,
				event_types: ( eventTypes && eventTypes.length ? eventTypes : [ 'single' ] ).join( ',' ),
				chunk_size: TecDataGenerator.chunkSize,
				run_id: runId || '',
			} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					var message = ( res && res.data && res.data.message ) || 'Error generating events. See console/logs for details.';
					setNotice( eventsNoticeEl, 'error', message );
					return;
				}

				runId = res.data.run_id;
				showSectionProgress( eventsProgress, eventsProgressFill, eventsProgressLabel, res.data.done, res.data.total, res.data.done + ' / ' + res.data.total + ' events generated' );

				if ( ! res.data.finished ) {
					return step();
				}

				setNotice( eventsNoticeEl, 'success', 'Generated ' + res.data.total + ' events (run: ' + runId + ').' );
			} );
		}

		return step().then( refreshCounts );
	}

	function runTicketsLoop( params ) {
		var runId = null;

		function step() {
			var body = Object.assign( {
				chunk_size: TecDataGenerator.chunkSize,
				run_id: runId || '',
			}, params );

			return postAjax( TecDataGenerator.actions.generateTickets, body ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					var message = ( res && res.data && res.data.message ) || 'Error generating tickets. See console/logs for details.';
					setNotice( ticketsNoticeEl, 'error', message );
					return;
				}

				if ( res.data.existing_event ) {
					setNotice( ticketsNoticeEl, 'success', 'Added ' + res.data.tickets + ' ticket(s) with ' + res.data.attendees + ' attendee(s) (run: ' + res.data.run_id + ').' );
					return;
				}

				runId = res.data.run_id;
				showSectionProgress( ticketsProgress, ticketsProgressFill, ticketsProgressLabel, res.data.done, res.data.total, res.data.done + ' / ' + res.data.total + ' containers generated' );

				if ( ! res.data.finished ) {
					return step();
				}

				setNotice( ticketsNoticeEl, 'success', 'Generated tickets on ' + res.data.total + ' containers (run: ' + runId + ').' );
			} );
		}

		return step().then( refreshCounts );
	}

	function runSeriesLoop( total, eventsPerSeries, withVenues, withOrganizers, ticketType, minAttendees, maxAttendees ) {
		var runId = null;

		function step() {
			return postAjax( TecDataGenerator.actions.generateSeries, {
				total: total,
				events_per_series: eventsPerSeries,
				with_venues: withVenues,
				with_organizers: withOrganizers,
				ticket_type: ticketType || 'rsvp',
				min_attendees: minAttendees,
				max_attendees: maxAttendees,
				chunk_size: TecDataGenerator.chunkSize,
				run_id: runId || '',
			} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					var message = ( res && res.data && res.data.message ) || 'Error generating series. See console/logs for details.';
					showSectionProgress( seriesProgress, seriesProgressFill, seriesProgressLabel, 0, total, '' );
					setNotice( seriesNoticeEl, 'error', message );
					return;
				}

				runId = res.data.run_id;
				showSectionProgress( seriesProgress, seriesProgressFill, seriesProgressLabel, res.data.done, res.data.total, res.data.done + ' / ' + res.data.total + ' series generated' );

				if ( ! res.data.finished ) {
					return step();
				}

				setNotice( seriesNoticeEl, 'success', 'Generated ' + res.data.total + ' series with ' + ( res.data.done / res.data.total ) + ' events each (run: ' + runId + ').' );
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
			if ( scenarioCancelBtn ) {
				scenarioCancelBtn.hidden = true;
			}
			return;
		}

		if ( 'running' === job.status ) {
			if ( scenarioBtn ) {
				scenarioBtn.disabled = true;
			}
			if ( scenarioTypeSelect ) {
				scenarioTypeSelect.disabled = true;
			}
			if ( scenarioCancelBtn ) {
				scenarioCancelBtn.hidden = false;
				scenarioCancelBtn.disabled = false;
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
		if ( scenarioCancelBtn ) {
			scenarioCancelBtn.hidden = true;
		}

		if ( 'completed' === job.status ) {
			showScenarioProgress( job.done, job.total, job.done + ' / ' + job.total + ' units generated' );
			setNotice( scenarioNoticeEl, 'success', scenarioResultMessage( job ) );
			setTimeout( function () {
				setNotice( scenarioNoticeEl, 'success', '' );
				scenarioProgress.hidden = true;
			}, 5000 );
		} else if ( 'failed' === job.status ) {
			setNotice( scenarioNoticeEl, 'error', 'Scenario failed: ' + ( job.error || 'unknown error' ) );
		}
	}

	function pollScenarioStatus( applyState ) {
		return postAjax( TecDataGenerator.actions.scenarioStatus ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				return null;
			}
			if ( applyState !== false ) {
				applyScenarioJobState( res.data );
			}
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
			setNotice( scenarioNoticeEl, 'info', 'Scenario generation started — this may take a while. You can close this tab and come back later to check progress.' );
			showScenarioProgress( 0, 0, 'Initializing scenario...' );

			postAjax( TecDataGenerator.actions.scheduleScenario, {
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

	if ( scenarioCancelBtn ) {
		scenarioCancelBtn.addEventListener( 'click', function () {
			if ( ! confirm( 'Cancel the running scenario and clear pending Action Scheduler tasks?' ) ) {
				return;
			}

			scenarioCancelBtn.disabled = true;
			setSpinner( scenarioSpinner, true );
			setNotice( scenarioNoticeEl, 'info', 'Canceling...' );

			postAjax( TecDataGenerator.actions.cancelScenario ).then( function ( res ) {
				scenarioCancelBtn.disabled = false;
				setSpinner( scenarioSpinner, false );

				if ( res && res.success ) {
					if ( scenarioPollTimer ) {
						clearInterval( scenarioPollTimer );
						scenarioPollTimer = null;
					}
					scenarioProgress.hidden = true;
					setNotice( scenarioNoticeEl, 'warning', res.data.message );
					refreshCounts();
				} else {
					setNotice( scenarioNoticeEl, 'error', 'Error canceling scenario. See console/logs.' );
				}
			} );
		} );
	}

	// Boot-time resume: if a scenario job is already running (tab was closed/reopened, or
	// another admin started one), reflect it immediately and keep polling.
	pollScenarioStatus( false ).then( function ( data ) {
		if ( data && 'running' === data.status ) {
			startScenarioPolling();
		}
	} );

	function runCleanupLoop() {
		function step() {
			return postAjax( TecDataGenerator.actions.cleanup, {
				chunk_size: TecDataGenerator.chunkSize,
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

	if ( eventsBtn ) {
		eventsBtn.addEventListener( 'click', function () {
			var total = parseInt( document.getElementById( 'tec-data-generator-events-total' ).value, 10 ) || 100;
			var containerEl = document.querySelector( 'input[name="tec-data-generator-events-container"]:checked' );
			var container = containerEl ? containerEl.value : 'event';
			var editorEl = document.querySelector( 'input[name="tec-data-generator-events-editor"]:checked' );
			var editor = editorEl ? editorEl.value : 'classic';
			var withVenues = document.getElementById( 'tec-data-generator-events-with-venues' );
			var withOrganizers = document.getElementById( 'tec-data-generator-events-with-organizers' );
			var eventTypes = Array.prototype.map.call(
				document.querySelectorAll( '.tec-data-generator-events-event-type:checked:not(:disabled)' ),
				function ( el ) { return el.value; }
			);

			eventsBtn.disabled = true;
			if ( cleanupBtn ) {
				cleanupBtn.disabled = true;
			}
			if ( ticketsBtn ) {
				ticketsBtn.disabled = true;
			}
			setSpinner( eventsSpinner, true );
			setNotice( eventsNoticeEl, 'info', 'Generating events — this may take a while. Please keep this page open.' );
			showSectionProgress( eventsProgress, eventsProgressFill, eventsProgressLabel, 0, total, '0 / ' + total + ' events generated' );

			runEventsLoop(
				total,
				container,
				editor,
				withVenues && withVenues.checked ? 1 : 0,
				withOrganizers && withOrganizers.checked ? 1 : 0,
				eventTypes
			).finally( function () {
				eventsBtn.disabled = false;
				if ( cleanupBtn ) {
					cleanupBtn.disabled = false;
				}
				if ( ticketsBtn ) {
					ticketsBtn.disabled = false;
				}
				setSpinner( eventsSpinner, false );
			} );
		} );
	}

	if ( ticketsBtn ) {
		ticketsBtn.addEventListener( 'click', function () {
			var eventId = parseInt( document.getElementById( 'tec-data-generator-tickets-event-id' ).value, 10 ) || 0;
			var containerEl = document.querySelector( 'input[name="tec-data-generator-tickets-container"]:checked' );
			var container = containerEl ? containerEl.value : 'event';
			var ticketTypeEl = document.querySelector( 'input[name="tec-data-generator-tickets-ticket-type"]:checked:not(:disabled)' );
			var ticketType = ticketTypeEl ? ticketTypeEl.value : 'rsvp';
			var editorEl = document.querySelector( 'input[name="tec-data-generator-tickets-editor"]:checked' );
			var editor = editorEl ? editorEl.value : 'classic';
			var eventTypes = Array.prototype.map.call(
				document.querySelectorAll( '.tec-data-generator-tickets-event-type:checked:not(:disabled)' ),
				function ( el ) { return el.value; }
			);

			var params;

			if ( eventId ) {
				var quantity = parseInt( document.getElementById( 'tec-data-generator-tickets-quantity' ).value, 10 ) || 1;
				var minA = parseInt( document.getElementById( 'tec-data-generator-tickets-min-attendees' ).value, 10 ) || 1;
				var maxA = parseInt( document.getElementById( 'tec-data-generator-tickets-max-attendees' ).value, 10 ) || 20;
				params = { event_id: eventId, quantity: quantity, ticket_type: ticketType, min_attendees: minA, max_attendees: maxA };
			} else {
				var total = parseInt( document.getElementById( 'tec-data-generator-tickets-total' ).value, 10 ) || 10;
				var minT = parseInt( document.getElementById( 'tec-data-generator-tickets-min' ).value, 10 ) || 1;
				var maxT = parseInt( document.getElementById( 'tec-data-generator-tickets-max' ).value, 10 ) || 1;
				var minAtt = parseInt( document.getElementById( 'tec-data-generator-tickets-min-attendees' ).value, 10 ) || 1;
				var maxAtt = parseInt( document.getElementById( 'tec-data-generator-tickets-max-attendees' ).value, 10 ) || 20;
				params = { total: total, container: container, editor: editor, ticket_type: ticketType, min_tickets: minT, max_tickets: maxT, min_attendees: minAtt, max_attendees: maxAtt, event_types: eventTypes.join( ',' ) };
			}

			ticketsBtn.disabled = true;
			if ( eventsBtn ) {
				eventsBtn.disabled = true;
			}
			if ( cleanupBtn ) {
				cleanupBtn.disabled = true;
			}
			setSpinner( ticketsSpinner, true );
			setNotice( ticketsNoticeEl, 'info', 'Generating tickets — this may take a while. Please keep this page open.' );
			showSectionProgress( ticketsProgress, ticketsProgressFill, ticketsProgressLabel, 0, params.total || 0, '0 / ' + ( params.total || 0 ) + ' containers generated' );

			runTicketsLoop( params ).finally( function () {
				ticketsBtn.disabled = false;
				if ( eventsBtn ) {
					eventsBtn.disabled = false;
				}
				if ( cleanupBtn ) {
					cleanupBtn.disabled = false;
				}
				setSpinner( ticketsSpinner, false );
			} );
		} );
	}

	if ( seriesBtn ) {
		seriesBtn.addEventListener( 'click', function () {
			var total = parseInt( document.getElementById( 'tec-data-generator-series-total' ).value, 10 ) || 5;
			var eventsPerSeries = parseInt( document.getElementById( 'tec-data-generator-series-events-per' ).value, 10 ) || 5;
			var ticketTypeEl = document.querySelector( 'input[name="tec-data-generator-series-ticket-type"]:checked' );
			var ticketType = ticketTypeEl ? ticketTypeEl.value : 'rsvp';
			var withVenues = document.getElementById( 'tec-data-generator-series-with-venues' );
			var withOrganizers = document.getElementById( 'tec-data-generator-series-with-organizers' );
			var minAttendees = parseInt( document.getElementById( 'tec-data-generator-series-min-attendees' ).value, 10 ) || 1;
			var maxAttendees = parseInt( document.getElementById( 'tec-data-generator-series-max-attendees' ).value, 10 ) || 20;

			seriesBtn.disabled = true;
			if ( eventsBtn ) {
				eventsBtn.disabled = true;
			}
			if ( ticketsBtn ) {
				ticketsBtn.disabled = true;
			}
			if ( cleanupBtn ) {
				cleanupBtn.disabled = true;
			}
			setSpinner( seriesSpinner, true );
			setNotice( seriesNoticeEl, 'info', 'Generating series — this may take a while. Please keep this page open.' );
			showSectionProgress( seriesProgress, seriesProgressFill, seriesProgressLabel, 0, total, '0 / ' + total + ' series generated' );

			runSeriesLoop(
				total,
				eventsPerSeries,
				withVenues && withVenues.checked ? 1 : 0,
				withOrganizers && withOrganizers.checked ? 1 : 0,
				ticketType,
				minAttendees,
				maxAttendees
			).finally( function () {
				seriesBtn.disabled = false;
				if ( eventsBtn ) {
					eventsBtn.disabled = false;
				}
				if ( ticketsBtn ) {
					ticketsBtn.disabled = false;
				}
				if ( cleanupBtn ) {
					cleanupBtn.disabled = false;
				}
				setSpinner( seriesSpinner, false );
			} );
		} );
	}

	if ( generateBtn ) {
		generateBtn.addEventListener( 'click', function () {
			var total = parseInt( document.getElementById( 'tec-data-generator-total' ).value, 10 ) || 5000;
			var minAttendees = parseInt( document.getElementById( 'tec-data-generator-min-attendees' ).value, 10 ) || 1;
			var maxAttendees = parseInt( document.getElementById( 'tec-data-generator-max-attendees' ).value, 10 ) || 20;
			var withVenues = withVenuesEl && withVenuesEl.checked ? 1 : 0;
			var withOrganizers = withOrganizersEl && withOrganizersEl.checked ? 1 : 0;
			var eventTypes = Array.prototype.map.call(
				document.querySelectorAll( '.tec-data-generator-event-type:checked:not(:disabled)' ),
				function ( el ) { return el.value; }
			);
			var ticketTypeEl = document.querySelector( 'input[name="tec-data-generator-ticket-type"]:checked:not(:disabled)' );
			var ticketType = ticketTypeEl ? ticketTypeEl.value : 'rsvp';

			generateBtn.disabled = true;
			cleanupBtn.disabled = true;
			if ( scenarioBtn ) {
				scenarioBtn.disabled = true;
			}
			setSpinner( generateSpinner, true );
			setNotice( generateNoticeEl, 'info', '' );

			runGenerateLoop( total, minAttendees, maxAttendees, withVenues, withOrganizers, eventTypes, ticketType ).finally( function () {
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

			if ( generateBtn ) {
				generateBtn.disabled = true;
			}
			cleanupBtn.disabled = true;
			if ( scenarioBtn ) {
				scenarioBtn.disabled = true;
			}
			if ( eventsBtn ) {
				eventsBtn.disabled = true;
			}
			if ( ticketsBtn ) {
				ticketsBtn.disabled = true;
			}
			setSpinner( generateSpinner, true );
			setNotice( generateNoticeEl, 'info', 'Cleanup in progress — this may take a while. Please keep this page open.' );
			showProgress( 0, 0, 'Starting cleanup...' );

			runCleanupLoop().finally( function () {
				if ( generateBtn ) {
					generateBtn.disabled = false;
				}
				cleanupBtn.disabled = false;
				if ( scenarioBtn ) {
					scenarioBtn.disabled = false;
				}
				if ( eventsBtn ) {
					eventsBtn.disabled = false;
				}
				if ( ticketsBtn ) {
					ticketsBtn.disabled = false;
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
		return postAjax( TecDataGenerator.actions.migrationStatus ).then( function ( res ) {
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
			runMigrationAction( TecDataGenerator.actions.runMigration, null );
		} );
	}

	if ( revertMigrationBtn ) {
		revertMigrationBtn.addEventListener( 'click', function () {
			runMigrationAction(
				TecDataGenerator.actions.revertMigration,
				'Revert the rsvp-to-tc migration back to legacy V1 RSVP data?'
			);
		} );
	}

	function runAddonAction( button, spinner, action, params, successMessage ) {
		button.disabled = true;
		setSpinner( spinner, true );
		setNotice( addonNoticeEl, 'info', '' );

		postAjax( action, params ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				var message = ( res && res.data && res.data.message ) || 'Error. See console/logs for details.';
				setNotice( addonNoticeEl, 'error', message );
				return;
			}

			setNotice( addonNoticeEl, 'success', successMessage( res.data ) );
			refreshCounts();
		} ).finally( function () {
			button.disabled = false;
			setSpinner( spinner, false );
		} );
	}

	if ( addRsvpBtn ) {
		addRsvpBtn.addEventListener( 'click', function () {
			var eventId = parseInt( document.getElementById( 'tec-data-generator-addrsvp-event-id' ).value, 10 ) || 0;
			var quantity = parseInt( document.getElementById( 'tec-data-generator-addrsvp-quantity' ).value, 10 ) || 1;

			runAddonAction( addRsvpBtn, addRsvpSpinner, TecDataGenerator.actions.addRsvp, { event_id: eventId, quantity: quantity }, function ( data ) {
				return 'Added ' + data.created + ' RSVP ticket(s) to event ' + eventId + ' (run: ' + data.run_id + ').';
			} );
		} );
	}

	if ( addTicketsBtn ) {
		addTicketsBtn.addEventListener( 'click', function () {
			var eventId = parseInt( document.getElementById( 'tec-data-generator-addtickets-event-id' ).value, 10 ) || 0;
			var quantity = parseInt( document.getElementById( 'tec-data-generator-addtickets-quantity' ).value, 10 ) || 1;

			runAddonAction( addTicketsBtn, addTicketsSpinner, TecDataGenerator.actions.addTickets, { event_id: eventId, quantity: quantity }, function ( data ) {
				return 'Added ' + data.created + ' paid ticket(s) to event ' + eventId + ' (run: ' + data.run_id + ').';
			} );
		} );
	}

	if ( addAttendeesBtn ) {
		addAttendeesBtn.addEventListener( 'click', function () {
			var ticketId = parseInt( document.getElementById( 'tec-data-generator-addattendees-ticket-id' ).value, 10 ) || 0;
			var quantity = parseInt( document.getElementById( 'tec-data-generator-addattendees-quantity' ).value, 10 ) || 1;

			runAddonAction( addAttendeesBtn, addAttendeesSpinner, TecDataGenerator.actions.addAttendees, { ticket_id: ticketId, quantity: quantity }, function ( data ) {
				return 'Added ' + data.created + ' attendee(s) to ticket ' + ticketId + ' (run: ' + data.run_id + ').';
			} );
		} );
	}
} )();
