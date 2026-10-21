<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Starter questions for the daily quiz. Editors add more in Admin → Quiz.
 */
class QuizQuestionSeeder extends Seeder
{
    public function run(): void
    {
        $questions = [
            ['Which Metro station has the longest escalator in the system?', ['Wheaton', 'Rosslyn', 'Forest Glen', 'Dupont Circle'], 0, 'Wheaton’s escalators run about 230 feet, the longest in the Western Hemisphere.'],
            ['Which is the deepest Metro station?', ['Rosslyn', 'Forest Glen', 'Wheaton', 'Tenleytown'], 1, 'Forest Glen is about 196 feet down, so riders use elevators instead of escalators.'],
            ['In what year did Metrorail open?', ['1969', '1972', '1976', '1981'], 2, 'The first stretch of the Red Line opened in March 1976.'],
            ['Which line opened first?', ['Blue', 'Orange', 'Red', 'Green'], 2, 'The Red Line opened between Farragut North and Rhode Island Avenue.'],
            ['How many Metrorail stations are there?', ['86', '91', '98', '104'], 2, 'Potomac Yard opened in 2023, bringing the count to 98.'],
            ['Who drew up the original plan for the city of Washington?', ['Benjamin Banneker', 'Pierre L’Enfant', 'Thomas Jefferson', 'Andrew Ellicott'], 1, 'Pierre L’Enfant’s 1791 plan laid out the diagonal avenues and circles.'],
            ['How many wards does DC have?', ['6', '8', '10', '12'], 1, 'DC is divided into eight wards, each with its own councilmember.'],
            ['What is DC’s official bird?', ['Cardinal', 'Wood thrush', 'Bald eagle', 'Northern mockingbird'], 1, 'The wood thrush has been the District’s bird since 1967.'],
            ['What is DC’s official tree?', ['Scarlet oak', 'Cherry', 'American elm', 'Red maple'], 0, 'The scarlet oak was named the official tree in 1960.'],
            ['The DC flag is based on whose family coat of arms?', ['Thomas Jefferson', 'Pierre L’Enfant', 'George Washington', 'John Adams'], 2, 'Its stars and bars come from George Washington’s family coat of arms.'],
            ['Who is known as the Godfather of Go-Go?', ['Chuck Brown', 'Marvin Gaye', 'Duke Ellington', 'Trouble Funk'], 0, 'Chuck Brown and the Soul Searchers shaped go-go in the 1970s.'],
            ['What sauce is DC carryout famous for?', ['Old Bay', 'Mumbo sauce', 'Comeback sauce', 'Alabama white sauce'], 1, 'Mumbo (or mambo) sauce is the sweet, tangy red sauce on wings and fries.'],
            ['How tall is the Washington Monument, to the nearest foot?', ['455 feet', '505 feet', '555 feet', '605 feet'], 2, 'It stands about 555 feet tall.'],
            ['In what year did the Nationals win the World Series?', ['2012', '2016', '2019', '2021'], 2, 'They beat the Houston Astros in seven games in 2019.'],
            ['In what year did the Capitals win the Stanley Cup?', ['2010', '2016', '2018', '2020'], 2, 'They beat the Vegas Golden Knights in 2018.'],
            ['In what year did the Mystics win their first WNBA title?', ['2015', '2017', '2019', '2021'], 2, 'Elena Delle Donne and the Mystics won in 2019.'],
            ['What were the Wizards called before 1997?', ['Senators', 'Bullets', 'Generals', 'Capitols'], 1, 'The team was the Washington Bullets until 1997.'],
            ['Which river forms DC’s border with Virginia?', ['Anacostia', 'Potomac', 'Rappahannock', 'Patuxent'], 1, 'The Potomac separates the District from Virginia.'],
            ['Where did DC’s famous cherry trees come from?', ['Kyoto', 'Tokyo', 'Osaka', 'Seoul'], 1, 'The city of Tokyo gave the trees in 1912.'],
            ['In what year could DC residents first vote for president?', ['1952', '1964', '1976', '1980'], 1, 'The 23rd Amendment gave DC electoral votes, first used in 1964.'],
            ['Who designed Metro’s coffered, vaulted stations?', ['I. M. Pei', 'Harry Weese', 'Frank Gehry', 'Eero Saarinen'], 1, 'Chicago architect Harry Weese designed the station style.'],
            ['Whose bequest founded the Smithsonian Institution?', ['James Smithson', 'Andrew Mellon', 'Joseph Henry', 'Charles Freer'], 0, 'British scientist James Smithson left his fortune to the United States.'],
            ['In what year did Rock Creek Park become a national park?', ['1876', '1890', '1916', '1933'], 1, 'Congress established it in 1890.'],
            ['Which law limits how tall DC buildings can be?', ['The Organic Act', 'The Height of Buildings Act', 'The Home Rule Act', 'The Plan of Washington Act'], 1, 'The Height of Buildings Act of 1910 sets the limits still in force.'],
            ['In what year did Ben’s Chili Bowl open on U Street?', ['1948', '1958', '1968', '1978'], 1, 'Ben and Virginia Ali opened it in 1958.'],
            ['How many MLS Cups has D.C. United won?', ['2', '3', '4', '5'], 2, 'They won in 1996, 1997, 1999 and 2004.'],
            ['In what year did Nationals Park open?', ['2005', '2008', '2011', '2014'], 1, 'The Nationals moved into the ballpark by the Navy Yard in 2008.'],
            ['When did the Silver Line open its first stations?', ['2009', '2014', '2018', '2022'], 1, 'Service to Wiehle-Reston East began in 2014.'],
        ];

        foreach ($questions as [$question, $choices, $answer, $explanation]) {
            DB::table('quiz_questions')->updateOrInsert(['question' => $question], [
                'choices' => json_encode($choices), 'answer' => $answer, 'explanation' => $explanation,
                'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
