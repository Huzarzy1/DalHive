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
    
    // update the gauge number
    document.getElementById('gauge-value').textContent = value;
    // update the gauge bar
    document.getElementById('gauge-fill').style.width = value + "%";
}

updateGauge();