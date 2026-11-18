let busynessData = {};

// Read the JSON file, organize the data
async function loadBusynessData() {

    try{
        const response = await fetch('./dalplex_besttime.json');

        if(!response.ok){
            throw new Error('Could not load JSON file');
        }

        const data = await response.json();

        //Go through each day in the JSON
        for (const day in data.populartimes) {
            busynessData[day] = [];

            for (let hour = 0; hour < 24; hour++) {
                busynessData[day].push(data.populartimes[day][hour]);
            }
        }       

        console.log(busynessData);

        return busynessData;
    }
    catch(error){
        console.error('Error loading busyness data: ', error);
        throw error;
    }
    
}

//Return all 24 values for a certain day
function getBusynessByDay(day){
    return busynessData[day];
}

//Return the value for a certain day and hour
function getBusynessByHour(day, hour){
    return busynessData[day]?.[hour];
}

export {loadBusynessData, getBusynessByDay, getBusynessByHour};