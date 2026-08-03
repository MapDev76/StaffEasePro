const fs = require('fs');
const filePath = '/Applications/MAMP/htdocs/StaffEasePro/assets/js/dashboard/assignments.js';
let src = fs.readFileSync(filePath, 'utf8');

const it = {
  "Oops!": "Errore",
  "Done": "Fatto",
  "Select at least one shift template.": "Seleziona almeno un modello di turno.",
  "Select one employee.": "Seleziona un dipendente.",
  "Forecast unavailable.": "Previsione non disponibile.",
  "Coverage forecast ready.": "Previsione di copertura pronta.",
  "Open slots now": "Turni aperti ora",
  "Projected open after run": "Aperti previsti dopo l'esecuzione",
  "Uncovered days": "Giorni non coperti",
  "Shift-days already covered": "Giorni-turno gi\u00e0 coperti",
  "Calculating forecast...": "Calcolo della previsione...",
  "Forecast failed.": "Previsione non riuscita.",
  "Auto-assignment failed.": "Assegnazione automatica non riuscita.",
  "Automatic assignment completed.": "Assegnazione automatica completata.",
  "slots assigned.": "assegnazioni create.",
  "Other dept": "Altro dipartimento",
  "Rest done": "Riposi effettuati",
  "Worked days": "Giorni lavorati",
  "Hide daily assignments list": "Nascondi lista quotidiana assegnazioni",
  "Show daily assignments list": "Mostra lista quotidiana assegnazioni",
  "Open now": "Aperti ora",
  "Projected covered": "Copertura prevista",
  "Priority dept": "Dipartimento prioritario",
  "Priority dept: none": "Dipartimento prioritario: nessuno",
  "Priority dept protected from external staff": "Dipartimento prioritario protetto dal personale esterno",
  "Priority dept can use external staff": "Il dipartimento prioritario pu\u00f2 usare personale esterno",
  "Uncovered days delta": "Delta giorni non coperti",
  "Policy window": "Finestra policy",
  "work": "lavoro",
  "rest": "riposo",
  "Open slots": "Turni aperti",
  "Assignments expected": "Assegnazioni previste",
  "Need to reach minimum": "Fabbisogno minimo",
  "Potential shortage detected for selected range.": "Possibile carenza rilevata per il periodo selezionato.",
  "Potential surplus detected for selected range.": "Possibile surplus rilevato per il periodo selezionato.",
  "Coverage looks balanced for selected range.": "La copertura appare bilanciata per il periodo selezionato.",
  "No active employees in scope. Add employees or expand scope.": "Nessun dipendente attivo nell'ambito. Aggiungi dipendenti o amplia l'ambito.",
  "Reduce weekly rest days or increase max work days for this run.": "Riduci i giorni di riposo settimanali o aumenta i giorni massimi di lavoro per questa esecuzione.",
  "Expand range and include more shift chips to rebalance load.": "Amplia il periodo e includi pi\u00f9 turni per riequilibrare il carico.",
  "Surplus detected: create extra shifts in departments with demand.": "Surplus rilevato: crea turni aggiuntivi nei dipartimenti con maggiore domanda.",
  "Estimated extra assignable capacity": "Capacit\u00e0 extra assegnabile stimata",
  "Open slots may remain because assignment is department-scoped.": "Alcuni turni aperti possono restare perch\u00e9 l'assegnazione \u00e8 limitata al dipartimento.",
  "Coverage is near target. Run auto-assign and re-check remaining open slots.": "La copertura \u00e8 vicina all'obiettivo. Avvia l'assegnazione automatica e ricontrolla i turni aperti restanti.",
  "Department": "Dipartimento",
  "Suggestion": "Suggerimento",
  "open": "aperti",
  "Most uncovered days": "Giorni pi\u00f9 scoperti",
  "Shift": "Turno",
  "Create extra shifts": "Crea turni aggiuntivi",
  "Fixed rest keeps routine stable but can cluster uncovered days.": "Il riposo fisso stabilizza la routine ma pu\u00f2 concentrare i giorni non coperti.",
  "Staggered rest helps distribute rest days across the week.": "La rotazione scalare aiuta a distribuire i riposi nella settimana.",
  "Random rest may improve distribution but varies at each run.": "La rotazione casuale pu\u00f2 migliorare la distribuzione ma varia a ogni esecuzione.",
  "Select at least one shift chip to run forecast.": "Seleziona almeno un turno per calcolare la previsione.",
  "Enable one or more shift chips before auto-assign.": "Attiva uno o pi\u00f9 turni prima dell'assegnazione automatica.",
  "Unknown error.": "Errore sconosciuto.",
  "Retry after updating period and limits.": "Riprova dopo aver aggiornato periodo e limiti.",
  "Weekly rest": "Riposo settimanale",
  "Leave": "Permesso",
  "Vacation": "Vacanza",
  "Sick leave": "Malattia",
  "Special day": "Giorno speciale",
  "Work": "Lavoro",
  "Remove unavailable date": "Rimuovi la data non disponibile",
  "All visible work shifts": "Tutti i turni di lavoro visibili",
  "No work shifts available in current scope.": "Nessun turno di lavoro disponibile nell'ambito attuale.",
  "Disable rotation": "Disattiva rotazione",
  "Rotation (+1 day/month)": "Rotazione (+1 giorno/mese)",
  "No base rest days defined.": "Nessun giorno di riposo base definito.",
  "No open shifts available for this employee in the selected range.": "Nessun turno aperto disponibile per questo dipendente nel periodo selezionato.",
  "No valid open shifts found in the current range.": "Nessun turno aperto valido trovato nel periodo corrente.",
  "Selected open shifts assigned successfully.": "Turni aperti selezionati assegnati con successo.",
  "No assignable open shifts were selected.": "Nessun turno aperto assegnabile selezionato.",
  "Error assigning selected open shifts.": "Errore durante l'assegnazione dei turni aperti selezionati.",
  "Week": "Settimana",
  "Available": "Disponibile",
  "Shift assigned successfully.": "Turno assegnato con successo.",
  "Assignment failed: ": "Assegnazione non riuscita: ",
  "unknown": "sconosciuto",
  "Error assigning shift.": "Errore durante l'assegnazione del turno.",
  "No shifts assigned for selected month.": "Nessun turno assegnato per il mese selezionato.",
  "No unavailable dates defined.": "Nessuna data non disponibile definita.",
  "Loading assigned shifts...": "Caricamento dei turni assegnati...",
  "Unable to load assigned shifts for selected month.": "Impossibile caricare i turni assegnati per il mese selezionato.",
  "Assigned": "Assegnato",
  "Sick": "Malattia",
  "Rest": "Riposo",
  "Employee": "Dipendente",
  "Overloaded employees": "Dipendenti sovraccarichi",
  "Employee overload detected": "Sovraccarico dipendente rilevato",
  "Suggested replacements": "Sostituzioni suggerite",
  "Invalid department.": "Dipartimento non valido.",
  "No work shifts found for this department.": "Nessun turno di lavoro trovato per questo dipartimento.",
  "shifts assigned": "turni assegnati",
  "Open remaining": "Turni aperti restanti",
  "Department auto-assign failed: ": "Assegnazione automatica del dipartimento non riuscita: ",
  "Error during department auto-assign.": "Errore durante l'assegnazione automatica del dipartimento.",
  "Clear all assignments for department \"${departmentName}\"?": "Cancellare tutte le assegnazioni del dipartimento \"${departmentName}\"?",
  "Confirm": "Conferma",
  "Confirmation dialog not available.": "Finestra di conferma non disponibile.",
  "assignment(s) cleared.": "assegnazione(i) rimossa(e).",
  "Clear department failed: ": "Cancellazione assegnazioni dipartimento non riuscita: ",
  "Error clearing department assignments.": "Errore durante la cancellazione delle assegnazioni del dipartimento.",
  "Run automatic assignment with current planner parameters?": "Avviare l'assegnazione automatica con i parametri attuali del pianificatore?"
};

const used = new Set();
const missing = new Set();
let count = 0;

// Matches tr('en', 'fr') / tr(`en`, `fr`) two-argument calls only.
const callRe = /\btr\(\s*(['"`])((?:\\.|(?!\1).)*)\1\s*,\s*(['"`])((?:\\.|(?!\3).)*)\3\s*\)/g;

src = src.replace(callRe, (m, q1, en) => {
  const val = it[en];
  if (val === undefined) {
    missing.add(en);
    return m;
  }
  used.add(en);
  count += 1;
  const lit = q1 === '`'
    ? '`' + val + '`'
    : "'" + val.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
  return m.slice(0, -1).replace(/\s*$/, '') + ', ' + lit + ')';
});

fs.writeFileSync(filePath, src, 'utf8');

const unused = Object.keys(it).filter((k) => !used.has(k));
console.log('Updated tr() calls:', count);
console.log('Missing translations:', missing.size ? JSON.stringify([...missing], null, 2) : 'none');
console.log('Unused dictionary keys:', unused.length ? JSON.stringify(unused, null, 2) : 'none');
