function initClinicDashboard() {
    const dashboard = document.querySelector('[data-clinic-dashboard]');
    if (!dashboard || dashboard.dataset.dashboardInitialized === 'true') return;
    dashboard.dataset.dashboardInitialized = 'true';
    const data = JSON.parse(dashboard.dataset.chartData || '{}');
    const bluePalette = ['#174B91', '#2F68AE', '#4C82C2', '#6B9AD0', '#8BB3DE'];
    const chartDefinitions = {
        visits: { type: 'line', labels: data.months, values: data.visits, label: 'Visits' },
        'visit-types': { type: 'doughnut', labels: data.visitTypeLabels, values: data.visitTypes, label: 'Visits' },
        inventory: { type: 'bar', labels: data.inventoryLabels, values: data.inventoryCounts, label: 'Medicine types' },
        examinations: { type: 'bar', labels: data.examLabels, values: data.examCounts, label: 'Examinations' },
    };

    if (typeof window.Chart === 'function') {
        dashboard.querySelectorAll('[data-dashboard-chart]').forEach((canvas) => {
            const definition = chartDefinitions[canvas.dataset.dashboardChart];
            if (!definition) return;
            const isLine = definition.type === 'line';
            new window.Chart(canvas.getContext('2d'), {
                type: definition.type,
                data: {
                    labels: definition.labels,
                    datasets: [{
                        label: definition.label,
                        data: definition.values,
                        borderColor: bluePalette[0],
                        backgroundColor: isLine ? 'rgba(23, 75, 145, 0.14)' : bluePalette,
                        borderWidth: 2,
                        tension: 0.28,
                        fill: isLine,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 300 },
                    plugins: {
                        legend: {
                            display: definition.type === 'doughnut',
                            position: 'bottom',
                            labels: { color: '#405570', boxWidth: 10, font: { size: 10 } },
                        },
                    },
                    scales: ['bar', 'line'].includes(definition.type) ? {
                        x: { ticks: { color: '#62748a', font: { size: 9 } }, grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0, color: '#62748a', font: { size: 9 } }, grid: { color: '#e9eef4' } },
                    } : {},
                },
            });
        });
    } else {
        dashboard.querySelectorAll('[data-dashboard-chart]').forEach((canvas) => {
            const message = document.createElement('p');
            message.className = 'clinic-dashboard__chart-error';
            message.textContent = 'Charts are unavailable. Refresh the page to try again.';
            canvas.replaceWith(message);
        });
    }

    const calendarGrid = dashboard.querySelector('[data-calendar-grid]');
    const calendarTitle = dashboard.querySelector('[data-calendar-title]');
    const calendarSelection = dashboard.querySelector('[data-calendar-selection]');
    const monthPattern = /^\d{4}-(0[1-9]|1[0-2])$/;
    let visibleMonth = dashboard.dataset.initialMonth;
    const localToday = new Date();
    let selectedDate = `${localToday.getFullYear()}-${String(localToday.getMonth() + 1).padStart(2, '0')}-${String(localToday.getDate()).padStart(2, '0')}`;
    let calendarEvents = new Map();
    let requestSequence = 0;

    function showCalendarMessage(message, isError = false) {
        calendarSelection.replaceChildren();
        const status = document.createElement('span');
        status.textContent = message;
        if (isError) status.setAttribute('role', 'alert');
        calendarSelection.append(status);
    }

    function showSelectedDate(dateValue) {
        selectedDate = dateValue;
        const date = new Date(`${dateValue}T12:00:00`);
        const title = document.createElement('strong');
        title.textContent = new Intl.DateTimeFormat(undefined, { weekday: 'long', month: 'long', day: 'numeric' }).format(date);
        const event = calendarEvents.get(dateValue);
        const details = document.createElement('div');
        details.className = 'clinic-dashboard__calendar-links';
        if (!event || (event.visits === 0 && event.examinations === 0)) {
            const description = document.createElement('span');
            description.textContent = 'No clinic visits or examinations scheduled.';
            details.append(description);
        } else {
            if (event.visits > 0) {
                const visitsLink = document.createElement('a');
                visitsLink.href = `index.php?page=all-clinic-visits&from=${encodeURIComponent(dateValue)}&to=${encodeURIComponent(dateValue)}`;
                visitsLink.textContent = `${event.visits} ${event.visits === 1 ? 'clinic visit' : 'clinic visits'}`;
                details.append(visitsLink);
            }
            if (event.examinations > 0) {
                const examinationsLink = document.createElement('a');
                examinationsLink.href = 'index.php?page=medical-records';
                examinationsLink.textContent = `${event.examinations} ${event.examinations === 1 ? 'examination' : 'examinations'} scheduled`;
                details.append(examinationsLink);
            }
        }
        calendarSelection.replaceChildren(title, details);
    }

    function renderCalendar() {
        if (!monthPattern.test(visibleMonth)) {
            showCalendarMessage('Calendar month is invalid. Refresh the page to try again.', true);
            return;
        }
        const [year, month] = visibleMonth.split('-').map(Number);
        calendarTitle.textContent = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1));
        calendarGrid.replaceChildren();
        const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        weekdays.forEach((weekday) => {
            const label = document.createElement('span');
            label.className = 'clinic-dashboard__weekday';
            label.textContent = weekday;
            calendarGrid.append(label);
        });

        const firstDay = new Date(year, month - 1, 1);
        const offset = (firstDay.getDay() + 6) % 7;
        const daysInMonth = new Date(year, month, 0).getDate();
        const cellCount = Math.ceil((offset + daysInMonth) / 7) * 7;
        const localNow = new Date();
        const today = `${localNow.getFullYear()}-${String(localNow.getMonth() + 1).padStart(2, '0')}-${String(localNow.getDate()).padStart(2, '0')}`;
        for (let index = 0; index < cellCount; index++) {
            const day = new Date(year, month - 1, index - offset + 1);
            const dateValue = `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`;
            const event = calendarEvents.get(dateValue);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'clinic-dashboard__day';
            button.textContent = String(day.getDate());
            button.setAttribute('aria-label', new Intl.DateTimeFormat(undefined, { dateStyle: 'full' }).format(day));
            if (day.getMonth() !== month - 1) button.classList.add('is-outside');
            if (dateValue === today) button.classList.add('is-today');
            if (dateValue === selectedDate) button.classList.add('is-selected');
            if (event && (event.visits > 0 || event.examinations > 0)) button.classList.add('has-events');
            button.addEventListener('click', () => showSelectedDate(dateValue));
            calendarGrid.append(button);
        }
        showSelectedDate(selectedDate);
    }

    async function loadCalendarMonth() {
        const sequence = ++requestSequence;
        calendarEvents = new Map();
        renderCalendar();
        showCalendarMessage('Loading clinic activity…');
        const url = new URL(dashboard.dataset.calendarApi, window.location.href);
        url.searchParams.set('month', visibleMonth);
        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok || payload.success !== true || !Array.isArray(payload.events)) {
                throw new Error(payload.message || 'The clinic calendar could not be loaded.');
            }
            if (sequence !== requestSequence) return;
            payload.events.forEach((event) => calendarEvents.set(event.date, event));
            renderCalendar();
        } catch (error) {
            if (sequence !== requestSequence) return;
            showCalendarMessage(error.message || 'The clinic calendar could not be loaded.', true);
        }
    }

    function changeMonth(offset) {
        const [year, month] = visibleMonth.split('-').map(Number);
        const changed = new Date(year, month - 1 + offset, 1);
        visibleMonth = `${changed.getFullYear()}-${String(changed.getMonth() + 1).padStart(2, '0')}`;
        loadCalendarMonth();
    }

    dashboard.querySelector('[data-calendar-prev]')?.addEventListener('click', () => changeMonth(-1));
    dashboard.querySelector('[data-calendar-next]')?.addEventListener('click', () => changeMonth(1));
    loadCalendarMonth();
}

function scheduleClinicDashboardInit() {
    window.requestAnimationFrame(initClinicDashboard);
}

window.addEventListener('page:loaded', scheduleClinicDashboardInit);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleClinicDashboardInit, { once: true });
} else {
    scheduleClinicDashboardInit();
}
