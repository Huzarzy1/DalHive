import {loadBusynessData, getBusynessByHour} from "./busynessData.js";

const day_names = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday'
]

// update the gauge with the busyness for the current day & hour
async function updateGauge() {
    await loadBusynessData();

    // grab current day and hour
    const current = new Date();
    let day = day_names[current.getDay()];
    let hour = current.getHours();

    // grab busyness value
    const value = getBusynessByHour(day, hour);

    document.getElementById('gauge').style.setProperty('--value', value);
    // the CSS appends "% Full" after the number once this is non-empty
    document.getElementById('gauge-value').textContent = value;

    
    const statusEl = document.getElementById('gauge-status');
    if (statusEl) {
        if (value <= 35) {
            statusEl.textContent = "Low Crowd • Plenty of Space";
            statusEl.className = "gauge-crowd-label crowd-low";
        } else if (value <= 70) {
            statusEl.textContent = "Moderately Busy";
            statusEl.className = "gauge-crowd-label crowd-mod";
        } else {
            statusEl.textContent = "Very Crowded • Peak Hours";
            statusEl.className = "gauge-crowd-label crowd-high";
        }
    }
}

updateGauge();